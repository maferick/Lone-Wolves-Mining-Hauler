<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

/**
 * src/Services/CorpContractsService.php
 *
 * Pull corporation contracts via ESI and hydrate:
 * - esi_corp_contract
 * - esi_corp_contract_item
 *
 * Requires a character token with correct scopes and corp roles.
 * Typical flow:
 *  1) Load sso_token (owner_type=character) for a director character in corp
 *  2) Ensure token is valid (refresh if needed)
 *  3) Call ESI:
 *     - GET /v1/corporations/{corporation_id}/contracts/
 *     - For each contract: GET /v1/corporations/{corporation_id}/contracts/{contract_id}/items/
 */
final class CorpContractsService
{
  private const REQUIRED_SCOPE = 'esi-contracts.read_corporation_contracts.v1';
  private const ACTIVE_REQUEST_STATUSES = [
    'requested',
    'awaiting_contract',
    'contract_linked',
    'contract_mismatch',
    'in_queue',
    'in_progress',
    'accepted',
    'in_transit',
    'posted',
    'submitted',
  ];
  private const TERMINAL_REQUEST_STATUSES = [
    'completed',
    'cancelled',
    'expired',
    'rejected',
    'delivered',
  ];

  public function __construct(
    private Db $db,
    private array $config,
    private EsiClient $esi,
    private SsoService $sso,
    private ?DiscordWebhookService $webhooks = null
  ) {}

  /**
   * Pull latest corp contracts and upsert into DB.
   * Returns summary array for logging.
   *
   * $characterOwnerId is the EVE character_id that owns the token.
   */
  public function pull(
    int $corpId,
    int $characterOwnerId,
    int $pageLimit = 50,
    int $lookbackHours = 24,
    array $options = []
  ): array {
    $options = array_merge([
      'max_runtime_seconds' => 0,
      'on_progress' => null,
    ], $options);
    $progressCb = is_callable($options['on_progress'] ?? null) ? $options['on_progress'] : null;
    $maxRuntimeSeconds = (int)($options['max_runtime_seconds'] ?? 0);
    $startedAt = microtime(true);

    $token = $this->sso->getToken('character', $corpId, $characterOwnerId);
    if (!$token) {
      throw new \RuntimeException("No sso_token found for corp_id={$corpId}, character_id={$characterOwnerId}");
    }
    $token = $this->sso->ensureAccessToken($token);
    $tokenId = (int)$token['token_id'];

    // ESI requires Bearer token for corp endpoints
    $bearer = $token['access_token'];

    $all = [];
    $page = 1;
    $fetchedContracts = 0;
    $upsertedContracts = 0;
    $upsertedItems = 0;
    $esiMs = 0;
    $dbMs = 0;
    $itemCalls = 0;
    $stopReason = null;

    $cutoffTs = time() - ($lookbackHours * 3600);

    while ($page <= $pageLimit) {
      if ($maxRuntimeSeconds > 0 && (microtime(true) - $startedAt) >= $maxRuntimeSeconds) {
        throw new \RuntimeException("ESI contracts pull exceeded {$maxRuntimeSeconds}s at page {$page}.");
      }
      $pageStart = microtime(true);
      $esiStart = microtime(true);
      $resp = $this->esiAuthedGetWithRefresh(
        $bearer,
        $tokenId,
        "/v1/corporations/{$corpId}/contracts/",
        ['page' => $page],
        $corpId,
        60
      );
      $esiMs += (microtime(true) - $esiStart) * 1000;

      if (!$resp['ok']) {
        // ESI returns 404 when paging past the last page.
        if ($resp['status'] === 404 && $page > 1) {
          $stopReason = 'end_of_pages_404';
          break;
        }
        // If ESI returns 404/403 on page 1, likely insufficient corp roles or scopes
        throw new \RuntimeException("ESI contracts pull failed (page {$page}): HTTP {$resp['status']}");
      }

      $contracts = is_array($resp['json']) ? $resp['json'] : [];
      if (count($contracts) === 0) {
        $stopReason = 'empty_page';
        break;
      }

      $totalPages = (int)($resp['headers']['x-pages'] ?? 0);
      $stopPaging = false;
      foreach ($contracts as $c) {
        $fetchedContracts++;
        $issuedAt = $c['date_issued'] ?? null;
        $issuedTs = $issuedAt ? strtotime((string)$issuedAt) : false;
        if ($issuedTs !== false && $issuedTs < $cutoffTs) {
          $stopPaging = true;
          continue;
        }
        $contractId = (int)($c['contract_id'] ?? 0);
        if ($contractId <= 0) continue;

        $dbStart = microtime(true);
        $this->upsertContract($corpId, $c, $resp['raw']);
        $dbMs += (microtime(true) - $dbStart) * 1000;
        $upsertedContracts++;

        // Pull items only for non-courier? PushX-like usually cares about courier volume/collateral/reward.
        // Still: keep it comprehensive and pull items when endpoint returns data.
        $itemCalls++;
        $esiStart = microtime(true);
        $itemsResp = $this->esiAuthedGetWithRefresh(
          $bearer,
          $tokenId,
          "/v1/corporations/{$corpId}/contracts/{$contractId}/items/",
          null,
          $corpId,
          300
        );
        $esiMs += (microtime(true) - $esiStart) * 1000;

        if ($itemsResp['ok'] && is_array($itemsResp['json'])) {
          $dbStart = microtime(true);
          $upsertedItems += $this->upsertContractItems($corpId, $contractId, $itemsResp['json'], $itemsResp['raw']);
          $dbMs += (microtime(true) - $dbStart) * 1000;
        }
        if ($maxRuntimeSeconds > 0 && (microtime(true) - $startedAt) >= $maxRuntimeSeconds) {
          throw new \RuntimeException("ESI contracts pull exceeded {$maxRuntimeSeconds}s while processing items.");
        }
      }

      if ($stopPaging) {
        $stopReason = 'cutoff_reached';
        break;
      }
      if ($totalPages > 0 && $page >= $totalPages) {
        $stopReason = 'last_page';
        break;
      }
      if ($progressCb) {
        $progressCb([
          'label' => sprintf('Contracts pull page %d (%d fetched)', $page, $fetchedContracts),
          'stage' => 'contracts_pull',
          'page' => $page,
          'fetched_contracts' => $fetchedContracts,
          'upserted_contracts' => $upsertedContracts,
          'upserted_items' => $upsertedItems,
          'page_ms' => (microtime(true) - $pageStart) * 1000,
        ]);
      }
      $page++;
    }
    if ($stopReason === null && $page > $pageLimit) {
      $stopReason = 'page_limit';
    }

    return [
      'corp_id' => $corpId,
      'character_id' => $characterOwnerId,
      'fetched_contracts' => $fetchedContracts,
      'upserted_contracts' => $upsertedContracts,
      'upserted_items' => $upsertedItems,
      'pages' => $page - 1,
      'duration_ms' => (microtime(true) - $startedAt) * 1000,
      'esi_ms' => $esiMs,
      'db_ms' => $dbMs,
      'item_calls' => $itemCalls,
      'stop_reason' => $stopReason ?? 'completed',
      'max_runtime_seconds' => $maxRuntimeSeconds,
    ];
  }

  public function findContractById(int $corpId, int $characterOwnerId, int $contractId, int $pageLimit = 50): ?array
  {
    $token = $this->sso->getToken('character', $corpId, $characterOwnerId);
    if (!$token) {
      throw new \RuntimeException("No sso_token found for corp_id={$corpId}, character_id={$characterOwnerId}");
    }
    $token = $this->sso->ensureAccessToken($token);
    $tokenId = (int)$token['token_id'];
    $bearer = $token['access_token'];

    $page = 1;
    while ($page <= $pageLimit) {
      $resp = $this->esiAuthedGetWithRefresh(
        $bearer,
        $tokenId,
        "/v1/corporations/{$corpId}/contracts/",
        ['page' => $page],
        $corpId,
        60
      );

      if (!$resp['ok']) {
        if ($resp['status'] === 404 && $page > 1) {
          break;
        }
        throw new \RuntimeException("ESI contracts lookup failed (page {$page}): HTTP {$resp['status']}");
      }

      $contracts = is_array($resp['json']) ? $resp['json'] : [];
      if (count($contracts) === 0) {
        break;
      }

      $totalPages = (int)($resp['headers']['x-pages'] ?? 0);
      foreach ($contracts as $c) {
        $currentId = (int)($c['contract_id'] ?? 0);
        if ($currentId === $contractId) {
          $this->upsertContract($corpId, $c, $resp['raw']);
          return $c;
        }
      }

      if ($totalPages > 0 && $page >= $totalPages) {
        break;
      }
      $page++;
    }

    return null;
  }

  /**
   * Reconcile linked/requested contract status from ESI for active + recent hauling requests.
   * Returns summary array for logging.
   */
  public function reconcileLinkedRequests(int $corpId, int $characterOwnerId, array $options = []): array
  {
    $options = array_merge([
      'page_limit' => 30,
      'finished_lookback_days' => 60,
    ], $options);

    $summary = [
      'corp_id' => $corpId,
      'character_id' => $characterOwnerId,
      'scanned' => 0,
      'updated' => 0,
      'skipped' => 0,
      'not_found' => 0,
      'errors' => 0,
      'pages' => 0,
      'timestamp' => gmdate('c'),
      'error_details' => [],
    ];

    $finishedCutoff = gmdate('Y-m-d H:i:s', time() - ((int)$options['finished_lookback_days'] * 86400));
    $activeStatuses = self::ACTIVE_REQUEST_STATUSES;
    $terminalStatuses = self::TERMINAL_REQUEST_STATUSES;

    $activePlaceholders = [];
    $terminalPlaceholders = [];
    $params = ['cid' => $corpId, 'cutoff' => $finishedCutoff];
    foreach ($activeStatuses as $i => $status) {
      $key = "active_{$i}";
      $activePlaceholders[] = ':' . $key;
      $params[$key] = $status;
    }
    foreach ($terminalStatuses as $i => $status) {
      $key = "terminal_{$i}";
      $terminalPlaceholders[] = ':' . $key;
      $params[$key] = $status;
    }

    $requests = $this->db->select(
      "SELECT request_id, request_key, corp_id, status, contract_id, esi_contract_id, contract_status, contract_type, contract_acceptor_id,
              from_location_id, to_location_id, ship_class,
              accepted_at, delivered_at, cancelled_at, volume_m3, collateral_isk, reward_isk, updated_at
         FROM haul_request
        WHERE corp_id = :cid
          AND (esi_contract_id IS NOT NULL OR contract_id IS NOT NULL)
          AND (
            status IN (" . implode(',', $activePlaceholders) . ")
            OR (status IN (" . implode(',', $terminalPlaceholders) . ") AND updated_at >= :cutoff)
          )",
      $params
    );

    $summary['scanned'] = count($requests);
    if ($requests === []) {
      return $summary;
    }

    $token = $this->sso->getToken('character', $corpId, $characterOwnerId);
    if (!$token) {
      $summary['errors'] = 1;
      $summary['error_details'][] = 'No sso_token found for corp contracts reconciliation.';
      return $summary;
    }

    $scopes = array_filter(preg_split('/\s+/', (string)($token['scopes'] ?? '')) ?: []);
    if (!in_array(self::REQUIRED_SCOPE, $scopes, true)) {
      $summary['errors'] = 1;
      $summary['error_details'][] = 'Missing scope: ' . self::REQUIRED_SCOPE . '. Re-auth a director character.';
      return $summary;
    }

    $token = $this->sso->ensureAccessToken($token);
    $tokenId = (int)$token['token_id'];
    $bearer = $token['access_token'];

    $targetIds = [];
    $activeIds = [];
    foreach ($requests as $request) {
      $contractId = (int)($request['esi_contract_id'] ?? $request['contract_id'] ?? 0);
      if ($contractId <= 0) {
        continue;
      }
      $targetIds[$contractId] = true;
      if (!in_array((string)($request['status'] ?? ''), $terminalStatuses, true)) {
        $activeIds[$contractId] = true;
      }
    }

    if ($targetIds === []) {
      return $summary;
    }

    $remaining = $targetIds;
    $remainingActive = $activeIds;
    $contractsById = [];
    $page = 1;
    $pageLimit = (int)$options['page_limit'];
    $cutoffTs = time() - ((int)$options['finished_lookback_days'] * 86400);

    while ($page <= $pageLimit) {
      $resp = $this->esiAuthedGetWithRefresh(
        $bearer,
        $tokenId,
        "/v1/corporations/{$corpId}/contracts/",
        ['page' => $page],
        $corpId,
        60
      );

      if (!$resp['ok']) {
        if ($resp['status'] === 404 && $page > 1) {
          break;
        }
        $summary['errors']++;
        $summary['error_details'][] = "ESI contracts reconcile failed (page {$page}): HTTP {$resp['status']}";
        if ($resp['status'] === 403 || $resp['status'] === 404) {
          break;
        }
        return $summary;
      }

      $contracts = is_array($resp['json']) ? $resp['json'] : [];
      if ($contracts === []) {
        break;
      }

      $summary['pages']++;
      foreach ($contracts as $contract) {
        $contractId = (int)($contract['contract_id'] ?? 0);
        if ($contractId <= 0 || !isset($remaining[$contractId])) {
          continue;
        }
        $contractsById[$contractId] = $contract;
        unset($remaining[$contractId]);
        unset($remainingActive[$contractId]);
        $this->upsertContract($corpId, $contract, $resp['raw']);
      }

      if ($remaining === []) {
        break;
      }

      $lastIssued = $contracts[count($contracts) - 1]['date_issued'] ?? null;
      $lastIssuedTs = $lastIssued ? strtotime((string)$lastIssued) : false;
      if ($lastIssuedTs !== false && $lastIssuedTs < $cutoffTs && $remainingActive === []) {
        break;
      }

      $totalPages = (int)($resp['headers']['x-pages'] ?? 0);
      if ($totalPages > 0 && $page >= $totalPages) {
        break;
      }
      $page++;
    }

    foreach ($requests as $request) {
      $contractId = (int)($request['esi_contract_id'] ?? $request['contract_id'] ?? 0);
      if ($contractId <= 0 || !isset($contractsById[$contractId])) {
        $summary['not_found']++;
        continue;
      }
      $updated = $this->reconcileRequestFromContract($request, $contractsById[$contractId]);
      if ($updated) {
        $summary['updated']++;
      } else {
        $summary['skipped']++;
      }
    }

    return $summary;
  }

  private function esiAuthedGet(
    string $bearer,
    string $path,
    ?array $query,
    int $corpId,
    int $ttl
  ): array {
    // Piggyback on EsiClient::request but with Authorization header.
    // We keep this local to avoid leaking auth into generic client.
    $base = rtrim($this->config['esi']['endpoints']['esi_base'] ?? 'https://esi.evetech.net', '/');
    $url = $base . '/' . ltrim($path, '/');

    $cacheEnabled = (bool)($this->config['esi']['cache']['enabled'] ?? true);
    $cacheKeyBin = Db::esiCacheKey('GET', $url, $query, null);
    $cached = ['hit' => false, 'json' => null, 'etag' => null];

    if ($cacheEnabled) {
      $cached = $this->db->esiCacheGet($corpId, $cacheKeyBin);
    }

    $qs = $query ? ('?' . http_build_query($query)) : '';
    $finalUrl = $url . $qs;

    $respHeaders = [];
    $ch = curl_init($finalUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, $this->config['esi']['user_agent'] ?? 'CorpHauling/1.0');
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $headerLine) use (&$respHeaders) {
      $len = strlen($headerLine);
      $headerLine = trim($headerLine);
      if ($headerLine === '' || !str_contains($headerLine, ':')) return $len;
      [$k, $v] = explode(':', $headerLine, 2);
      $respHeaders[strtolower(trim($k))] = trim($v);
      return $len;
    });

    $reqHeaders = [
      'Accept: application/json',
      'Authorization: Bearer ' . $bearer,
    ];
    if ($cacheEnabled && !empty($cached['etag'])) {
      $reqHeaders[] = 'If-None-Match: ' . $cached['etag'];
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);

    $raw = '';
    $errno = 0;
    $err = null;
    $status = 0;
    $attempt = 0;

    while (true) {
      $raw = (string)curl_exec($ch);
      $errno = curl_errno($ch);
      $err = $errno ? curl_error($ch) : null;
      $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

      if (!$errno && EsiRateLimiter::shouldRetry($status, $this->config) && $attempt < 1) {
        curl_close($ch);
        EsiRateLimiter::sleepForRetry($respHeaders, $this->config);
        $respHeaders = [];
        $ch = curl_init($finalUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->config['esi']['user_agent'] ?? 'CorpHauling/1.0');
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $headerLine) use (&$respHeaders) {
          $len = strlen($headerLine);
          $headerLine = trim($headerLine);
          if ($headerLine === '' || !str_contains($headerLine, ':')) return $len;
          [$k, $v] = explode(':', $headerLine, 2);
          $respHeaders[strtolower(trim($k))] = trim($v);
          return $len;
        });
        curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);
        $attempt++;
        continue;
      }

      break;
    }

    curl_close($ch);

    if (!$errno) {
      EsiRateLimiter::sleepIfLowRemaining($respHeaders, $this->config);
    }

    if ($status === 304 && $cacheEnabled && $cached['hit'] && $cached['json'] !== null) {
      $raw = $cached['json'];
      $this->db->esiCachePut($corpId, $cacheKeyBin, 'GET', $url, $query, null, 200, $cached['etag'], $respHeaders['last-modified'] ?? null, $ttl, $raw);
      return ['ok' => true, 'status' => 200, 'headers' => $respHeaders, 'json' => Db::jsonDecode($raw, null), 'raw' => $raw, 'from_cache' => true];
    }

    if ($errno) {
      if ($cacheEnabled && $cached['hit'] && $cached['json'] !== null) {
        return ['ok' => true, 'status' => 200, 'headers' => $respHeaders, 'json' => Db::jsonDecode($cached['json'], null), 'raw' => $cached['json'], 'from_cache' => true, 'warning' => $err];
      }
      return ['ok' => false, 'status' => 0, 'headers' => $respHeaders, 'json' => null, 'raw' => '', 'from_cache' => false, 'error' => $err];
    }

    if ($cacheEnabled) {
      $this->db->esiCachePut($corpId, $cacheKeyBin, 'GET', $url, $query, null, $status, $respHeaders['etag'] ?? null, $respHeaders['last-modified'] ?? null, $ttl, $raw, ($status >= 400 ? ('HTTP ' . $status) : null));
    }

    $decoded = null;
    try { $decoded = Db::jsonDecode($raw, null); } catch (\Throwable $e) {}
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'headers' => $respHeaders, 'json' => $decoded, 'raw' => $raw, 'from_cache' => false];
  }

  private function esiAuthedGetWithRefresh(
    string &$bearer,
    int $tokenId,
    string $path,
    ?array $query,
    int $corpId,
    int $ttl
  ): array {
    $resp = $this->esiAuthedGet($bearer, $path, $query, $corpId, $ttl);
    if ($resp['ok'] || $resp['status'] !== 401) {
      return $resp;
    }

    $token = $this->sso->refreshToken($tokenId);
    $bearer = $token['access_token'];
    $retry = $this->esiAuthedGet($bearer, $path, $query, $corpId, $ttl);
    $retry['refreshed'] = true;
    return $retry;
  }

  private function upsertContract(int $corpId, array $c, string $rawJson): void
  {
    $contractId = (int)($c['contract_id'] ?? 0);

    $this->db->execute(
      "INSERT INTO esi_corp_contract
        (corp_id, contract_id, issuer_id, issuer_corp_id, assignee_id, acceptor_id,
         start_location_id, end_location_id, title, type, status, availability,
         date_issued, date_expired, date_accepted, date_completed, days_to_complete,
         collateral_isk, reward_isk, buyout_isk, price_isk, volume_m3, raw_json, last_fetched_at)
       VALUES
        (:corp_id, :contract_id, :issuer_id, :issuer_corp_id, :assignee_id, :acceptor_id,
         :start_location_id, :end_location_id, :title, :type, :status, :availability,
         :date_issued, :date_expired, :date_accepted, :date_completed, :days_to_complete,
         :collateral_isk, :reward_isk, :buyout_isk, :price_isk, :volume_m3, :raw_json, UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE
         issuer_id=VALUES(issuer_id),
         issuer_corp_id=VALUES(issuer_corp_id),
         assignee_id=VALUES(assignee_id),
         acceptor_id=VALUES(acceptor_id),
         start_location_id=VALUES(start_location_id),
         end_location_id=VALUES(end_location_id),
         title=VALUES(title),
         type=VALUES(type),
         status=VALUES(status),
         availability=VALUES(availability),
         date_issued=VALUES(date_issued),
         date_expired=VALUES(date_expired),
         date_accepted=VALUES(date_accepted),
         date_completed=VALUES(date_completed),
         days_to_complete=VALUES(days_to_complete),
         collateral_isk=VALUES(collateral_isk),
         reward_isk=VALUES(reward_isk),
         buyout_isk=VALUES(buyout_isk),
         price_isk=VALUES(price_isk),
         volume_m3=VALUES(volume_m3),
         raw_json=VALUES(raw_json),
         last_fetched_at=UTC_TIMESTAMP()",
      [
        'corp_id' => $corpId,
        'contract_id' => $contractId,
        'issuer_id' => $c['issuer_id'] ?? null,
        'issuer_corp_id' => $c['issuer_corporation_id'] ?? null,
        'assignee_id' => $c['assignee_id'] ?? null,
        'acceptor_id' => $c['acceptor_id'] ?? null,
        'start_location_id' => $c['start_location_id'] ?? null,
        'end_location_id' => $c['end_location_id'] ?? null,
        'title' => $c['title'] ?? null,
        'type' => $c['type'] ?? 'unknown',
        'status' => $c['status'] ?? 'unknown',
        'availability' => $c['availability'] ?? null,
        'date_issued' => $this->dt($c['date_issued'] ?? null),
        'date_expired' => $this->dt($c['date_expired'] ?? null),
        'date_accepted' => $this->dt($c['date_accepted'] ?? null),
        'date_completed' => $this->dt($c['date_completed'] ?? null),
        'days_to_complete' => $c['days_to_complete'] ?? null,
        'collateral_isk' => $c['collateral'] ?? null,
        'reward_isk' => $c['reward'] ?? null,
        'buyout_isk' => $c['buyout'] ?? null,
        'price_isk' => $c['price'] ?? null,
        'volume_m3' => $c['volume'] ?? null,
        'raw_json' => Db::jsonEncode($c),
      ]
    );
  }

  private function upsertContractItems(int $corpId, int $contractId, array $items, string $rawJson): int
  {
    $count = 0;

    // Strategy: delete and re-insert for determinism (items list small). Safer than complex diff.
    $this->db->execute(
      "DELETE FROM esi_corp_contract_item WHERE corp_id = :corp_id AND contract_id = :contract_id",
      ['corp_id' => $corpId, 'contract_id' => $contractId]
    );

    $i = 1;
    foreach ($items as $it) {
      $typeId = (int)($it['type_id'] ?? 0);
      if ($typeId <= 0) continue;

      // ESI doesn't always provide a stable item_id. We'll generate a synthetic sequence.
      $itemId = $i++;

      $this->db->execute(
        "INSERT INTO esi_corp_contract_item
          (corp_id, contract_id, item_id, type_id, quantity, is_included, is_singleton, raw_json)
         VALUES
          (:corp_id, :contract_id, :item_id, :type_id, :quantity, :is_included, :is_singleton, :raw_json)",
        [
          'corp_id' => $corpId,
          'contract_id' => $contractId,
          'item_id' => $itemId,
          'type_id' => $typeId,
          'quantity' => $it['quantity'] ?? 0,
          'is_included' => isset($it['is_included']) ? (int)(!!$it['is_included']) : 1,
          'is_singleton' => isset($it['is_singleton']) ? (int)(!!$it['is_singleton']) : 0,
          'raw_json' => Db::jsonEncode($it),
        ]
      );
      $count++;
    }

    return $count;
  }

  private function dt($iso): ?string
  {
    if (!$iso) return null;
    // ESI returns ISO8601, store as UTC datetime
    $ts = strtotime((string)$iso);
    if ($ts === false) return null;
    return gmdate('Y-m-d H:i:s', $ts);
  }

  private function reconcileRequestFromContract(array $request, array $contract): bool
  {
    $requestId = (int)($request['request_id'] ?? 0);
    if ($requestId <= 0) {
      return false;
    }

    $changes = [];
    $contractStatus = (string)($contract['status'] ?? 'unknown');
    $contractType = (string)($contract['type'] ?? 'unknown');
    $contractType = in_array($contractType, ['courier', 'item_exchange', 'auction'], true) ? $contractType : 'unknown';
    $acceptorId = isset($contract['acceptor_id']) ? (int)$contract['acceptor_id'] : null;
    $currentAcceptor = $request['contract_acceptor_id'] ?? null;
    $currentAcceptor = $currentAcceptor !== null ? (int)$currentAcceptor : null;
    $volume = $contract['volume'] ?? null;
    $collateral = $contract['collateral'] ?? null;
    $reward = $contract['reward'] ?? null;

    if ((string)($request['contract_status'] ?? '') !== $contractStatus) {
      $changes['contract_status'] = $contractStatus;
    }
    if ((string)($request['contract_status_esi'] ?? '') !== $contractStatus) {
      $changes['contract_status_esi'] = $contractStatus;
    }
    if ((string)($request['esi_status'] ?? '') !== $contractStatus) {
      $changes['esi_status'] = $contractStatus;
    }
    if ((string)($request['contract_type'] ?? '') !== $contractType) {
      $changes['contract_type'] = $contractType;
    }
    $acceptorChanged = $currentAcceptor !== $acceptorId;
    if ($acceptorChanged) {
      $changes['contract_acceptor_id'] = $acceptorId;
    }
    if ((int)($request['esi_acceptor_id'] ?? 0) !== (int)($acceptorId ?? 0)) {
      $changes['esi_acceptor_id'] = $acceptorId;
    }

    if ($volume !== null && $this->differsFloat($request['volume_m3'] ?? null, $volume)) {
      $changes['volume_m3'] = $volume;
    }
    if ($collateral !== null && $this->differsFloat($request['collateral_isk'] ?? null, $collateral)) {
      $changes['collateral_isk'] = $collateral;
    }
    if ($reward !== null && $this->differsFloat($request['reward_isk'] ?? null, $reward)) {
      $changes['reward_isk'] = $reward;
    }

    $currentStatus = (string)($request['status'] ?? '');
    $newStatus = $this->mapContractStatusToRequestStatus($contractStatus);
    if ($newStatus !== null && $this->shouldUpdateRequestStatus($currentStatus, $newStatus)) {
      $changes['status'] = $newStatus;
    }

    $acceptedAt = $this->dt($contract['date_accepted'] ?? null);
    $completedAt = $this->dt($contract['date_completed'] ?? null);
    $expiredAt = $this->dt($contract['date_expired'] ?? null);
    if (in_array($newStatus ?? $currentStatus, ['accepted', 'in_progress', 'in_transit'], true) && $acceptedAt !== null) {
      if ((string)($request['accepted_at'] ?? '') !== $acceptedAt) {
        $changes['accepted_at'] = $acceptedAt;
      }
    }
    if (in_array($newStatus ?? $currentStatus, ['completed', 'delivered'], true) && $completedAt !== null) {
      if ((string)($request['delivered_at'] ?? '') !== $completedAt) {
        $changes['delivered_at'] = $completedAt;
      }
    }
    if (in_array($newStatus ?? $currentStatus, ['cancelled', 'expired', 'rejected'], true)) {
      $cancelAt = $completedAt ?? $expiredAt;
      if ($cancelAt !== null && (string)($request['cancelled_at'] ?? '') !== $cancelAt) {
        $changes['cancelled_at'] = $cancelAt;
      }
    }

    if ($changes === []) {
      return false;
    }

    $set = [];
    $params = ['rid' => $requestId];
    foreach ($changes as $column => $value) {
      $set[] = "{$column} = :{$column}";
      $params[$column] = $value;
    }
    $set[] = "updated_at = UTC_TIMESTAMP()";

    $this->db->execute(
      "UPDATE haul_request SET " . implode(', ', $set) . " WHERE request_id = :rid",
      $params
    );

    return true;
  }

  private function mapContractStatusToRequestStatus(string $contractStatus): ?string
  {
    return match ($contractStatus) {
      'outstanding' => 'in_queue',
      'in_progress' => 'in_progress',
      'finished', 'finished_issuer', 'finished_contractor', 'completed' => 'completed',
      'rejected' => 'rejected',
      'expired' => 'expired',
      'failed', 'deleted', 'reversed', 'cancelled' => 'cancelled',
      default => null,
    };
  }

  private function shouldUpdateRequestStatus(string $currentStatus, string $newStatus): bool
  {
    if ($newStatus === '' || $newStatus === $currentStatus) {
      return false;
    }
    $currentTerminal = in_array($currentStatus, self::TERMINAL_REQUEST_STATUSES, true);
    $newTerminal = in_array($newStatus, self::TERMINAL_REQUEST_STATUSES, true);
    if ($currentTerminal && !$newTerminal) {
      return false;
    }
    return true;
  }

  private function differsFloat($a, $b, float $epsilon = 0.01): bool
  {
    if ($a === null && $b === null) {
      return false;
    }
    $aVal = $a === null ? null : (float)$a;
    $bVal = $b === null ? null : (float)$b;
    if ($aVal === null || $bVal === null) {
      return $aVal !== $bVal;
    }
    return abs($aVal - $bVal) > $epsilon;
  }

}
