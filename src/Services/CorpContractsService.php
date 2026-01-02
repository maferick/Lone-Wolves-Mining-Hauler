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
  public function __construct(
    private Db $db,
    private array $config,
    private EsiClient $esi,
    private SsoService $sso
  ) {}

  /**
   * Pull latest corp contracts and upsert into DB.
   * Returns summary array for logging.
   *
   * $characterOwnerId is the EVE character_id that owns the token.
   */
  public function pull(int $corpId, int $characterOwnerId, int $pageLimit = 50, int $lookbackHours = 24): array
  {
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

    $cutoffTs = time() - ($lookbackHours * 3600);

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
        // ESI returns 404 when paging past the last page.
        if ($resp['status'] === 404 && $page > 1) {
          break;
        }
        // If ESI returns 404/403 on page 1, likely insufficient corp roles or scopes
        throw new \RuntimeException("ESI contracts pull failed (page {$page}): HTTP {$resp['status']}");
      }

      $contracts = is_array($resp['json']) ? $resp['json'] : [];
      if (count($contracts) === 0) break; // no more pages

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

        $this->upsertContract($corpId, $c, $resp['raw']);
        $upsertedContracts++;

        // Pull items only for non-courier? PushX-like usually cares about courier volume/collateral/reward.
        // Still: keep it comprehensive and pull items when endpoint returns data.
        $itemsResp = $this->esiAuthedGetWithRefresh(
          $bearer,
          $tokenId,
          "/v1/corporations/{$corpId}/contracts/{$contractId}/items/",
          null,
          $corpId,
          300
        );

        if ($itemsResp['ok'] && is_array($itemsResp['json'])) {
          $upsertedItems += $this->upsertContractItems($corpId, $contractId, $itemsResp['json'], $itemsResp['raw']);
        }
      }

      if ($stopPaging) {
        break;
      }
      if ($totalPages > 0 && $page >= $totalPages) {
        break;
      }
      $page++;
    }

    return [
      'corp_id' => $corpId,
      'character_id' => $characterOwnerId,
      'fetched_contracts' => $fetchedContracts,
      'upserted_contracts' => $upsertedContracts,
      'upserted_items' => $upsertedItems,
      'pages' => $page - 1,
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
}
