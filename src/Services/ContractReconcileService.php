<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

/**
 * src/Services/ContractReconcileService.php
 *
 * Syncs linked haul requests with the latest corp contract data already pulled into esi_corp_contract.
 */
final class ContractReconcileService
{
  public function __construct(
    private Db $db,
    private array $config,
    private EsiClient $esi,
    private ?DiscordWebhookService $webhooks = null,
    private ?DiscordEventService $discordEvents = null
  ) {
  }

  public function reconcile(int $corpId, array $options = []): array
  {
    $options = array_merge([
      'max_runtime_seconds' => 0,
      'on_progress' => null,
    ], $options);
    $progressCb = is_callable($options['on_progress'] ?? null) ? $options['on_progress'] : null;
    $maxRuntimeSeconds = (int)($options['max_runtime_seconds'] ?? 0);
    $startedAt = microtime(true);

    $summary = [
      'corp_id' => $corpId,
      'linked_requests' => 0,
      'updated_requests' => 0,
      'skipped_requests' => 0,
      'missing_contracts' => 0,
      'state_transitions' => 0,
      'webhook_enqueued' => 0,
      'errors' => 0,
      'scanned' => 0,
      'updated' => 0,
      'skipped' => 0,
      'not_found' => 0,
      'pages' => 0,
      'timestamp' => gmdate('c'),
      'duration_ms' => 0,
      'db_ms' => 0,
      'acceptor_resolves' => 0,
      'acceptor_resolve_ms' => 0,
      'stop_reason' => null,
      'max_runtime_seconds' => $maxRuntimeSeconds,
    ];

    $requests = $this->db->select(
      "SELECT request_id, request_key, contract_id, esi_contract_id, contract_status_esi, esi_status,
              contract_state, contract_lifecycle, contract_acceptor_id, contract_acceptor_name,
              acceptor_id, acceptor_name, esi_acceptor_id, esi_acceptor_name,
              last_contract_hash, contract_hash, date_accepted, date_completed, date_expired,
              from_location_id, from_location_type, to_location_id, to_location_type,
              ship_class, volume_m3, collateral_isk, reward_isk
         FROM haul_request
        WHERE corp_id = :cid
          AND (esi_contract_id IS NOT NULL OR contract_id IS NOT NULL)",
      ['cid' => $corpId]
    );

    $summary['linked_requests'] = count($requests);
    $summary['scanned'] = $summary['linked_requests'];
    if ($requests === []) {
      $summary['duration_ms'] = (microtime(true) - $startedAt) * 1000;
      $summary['stop_reason'] = 'no_requests';
      return $summary;
    }

    $contractIds = [];
    foreach ($requests as $request) {
      $contractId = (int)($request['esi_contract_id'] ?? $request['contract_id'] ?? 0);
      if ($contractId > 0) {
        $contractIds[$contractId] = true;
      }
    }

    if ($contractIds === []) {
      $summary['duration_ms'] = (microtime(true) - $startedAt) * 1000;
      $summary['stop_reason'] = 'no_contract_ids';
      return $summary;
    }

    $placeholders = [];
    $params = ['cid' => $corpId];
    $i = 0;
    foreach (array_keys($contractIds) as $contractId) {
      $key = 'c' . $i++;
      $placeholders[] = ':' . $key;
      $params[$key] = $contractId;
    }

    $contracts = $this->db->select(
      "SELECT contract_id, status, acceptor_id, date_accepted, date_completed, date_expired
         FROM esi_corp_contract
        WHERE corp_id = :cid
          AND contract_id IN (" . implode(',', $placeholders) . ")",
      $params
    );

    $contractsById = [];
    foreach ($contracts as $contract) {
      $contractsById[(int)($contract['contract_id'] ?? 0)] = $contract;
    }

    $entityResolver = new EntityResolveService($this->db, $this->esi);

    $processedRequests = 0;
    foreach ($requests as $request) {
      $requestId = (int)($request['request_id'] ?? 0);
      $contractId = (int)($request['contract_id'] ?? 0);
      if ($requestId <= 0 || $contractId <= 0) {
        $summary['skipped_requests']++;
        $summary['skipped']++;
        $processedRequests++;
        continue;
      }

      if (!isset($contractsById[$contractId])) {
        $summary['missing_contracts']++;
        $summary['not_found']++;
        $processedRequests++;
        continue;
      }

      $contract = $contractsById[$contractId];
      $contractStatus = (string)($contract['status'] ?? '');
      $acceptorIdRaw = (int)($contract['acceptor_id'] ?? 0);
      $acceptorId = $acceptorIdRaw > 0 ? $acceptorIdRaw : null;
      $dateAccepted = $contract['date_accepted'] ?? null;
      $dateCompleted = $contract['date_completed'] ?? null;
      $dateExpired = $contract['date_expired'] ?? null;

      $acceptorName = '';
      if ($acceptorId !== null) {
        try {
          $resolveStart = microtime(true);
          $acceptorName = $entityResolver->resolveCharacterName($acceptorId);
          $summary['acceptor_resolve_ms'] += (microtime(true) - $resolveStart) * 1000;
          $summary['acceptor_resolves']++;
        } catch (\Throwable $e) {
          $summary['errors']++;
          $acceptorName = '';
        }
      }

      $newState = $this->deriveContractLifecycle(
        $contractId,
        $contractStatus,
        $acceptorId,
        $dateAccepted,
        $dateCompleted,
        $dateExpired
      );
      $oldState = (string)($request['contract_lifecycle'] ?? $request['contract_state'] ?? '');
      $contractHash = $this->buildContractHash($contractId, $contractStatus, $acceptorId, $dateAccepted, $dateCompleted, $dateExpired);
      $previousHash = (string)($request['contract_hash'] ?? $request['last_contract_hash'] ?? '');

      $changes = [];
      $currentEsiContractId = $request['esi_contract_id'] ?? null;
      $currentEsiContractId = $currentEsiContractId !== null ? (int)$currentEsiContractId : null;
      if ($currentEsiContractId !== $contractId) {
        $changes['esi_contract_id'] = $contractId;
      }
      if ($contractHash !== $previousHash) {
        $changes['contract_status_esi'] = $contractStatus;
        $changes['esi_status'] = $contractStatus;
        $changes['esi_acceptor_id'] = $acceptorId;
        $changes['contract_lifecycle'] = $newState;
        $changes['date_accepted'] = $dateAccepted;
        $changes['date_completed'] = $dateCompleted;
        $changes['date_expired'] = $dateExpired;
        $changes['contract_hash'] = $contractHash;
        $changes['last_contract_hash'] = $contractHash;
      }

      $currentAcceptorLegacy = $request['contract_acceptor_id'] ?? null;
      $currentAcceptorLegacy = $currentAcceptorLegacy !== null ? (int)$currentAcceptorLegacy : null;
      if ($currentAcceptorLegacy !== $acceptorId) {
        $changes['contract_acceptor_id'] = $acceptorId;
      }
      $currentAcceptor = $request['acceptor_id'] ?? null;
      $currentAcceptor = $currentAcceptor !== null ? (int)$currentAcceptor : null;
      if ($currentAcceptor !== $acceptorId) {
        $changes['acceptor_id'] = $acceptorId;
      }
      $currentEsiAcceptor = $request['esi_acceptor_id'] ?? null;
      $currentEsiAcceptor = $currentEsiAcceptor !== null ? (int)$currentEsiAcceptor : null;
      if ($currentEsiAcceptor !== $acceptorId) {
        $changes['esi_acceptor_id'] = $acceptorId;
      }

      if ($acceptorId === null) {
        if (!empty($request['contract_acceptor_name'])) {
          $changes['contract_acceptor_name'] = null;
        }
        if (!empty($request['acceptor_name'])) {
          $changes['acceptor_name'] = null;
        }
        if (!empty($request['esi_acceptor_name'])) {
          $changes['esi_acceptor_name'] = null;
        }
      } elseif ($acceptorName !== '') {
        if ((string)($request['contract_acceptor_name'] ?? '') !== $acceptorName) {
          $changes['contract_acceptor_name'] = $acceptorName;
        }
        if ((string)($request['acceptor_name'] ?? '') !== $acceptorName) {
          $changes['acceptor_name'] = $acceptorName;
        }
        if ((string)($request['esi_acceptor_name'] ?? '') !== $acceptorName) {
          $changes['esi_acceptor_name'] = $acceptorName;
        }
      }

      if ($newState !== $oldState) {
        $changes['contract_state'] = $newState;
        $changes['contract_lifecycle'] = $newState;
      }

      if ($changes === []) {
        $summary['skipped_requests']++;
        $summary['skipped']++;
        $processedRequests++;
        continue;
      }

      $set = [];
      $params = ['rid' => $requestId];
      foreach ($changes as $column => $value) {
        $set[] = "{$column} = :{$column}";
        $params[$column] = $value;
      }
      $set[] = 'last_reconciled_at = UTC_TIMESTAMP()';
      $set[] = 'updated_at = UTC_TIMESTAMP()';

      $dbStart = microtime(true);
      $this->db->execute(
        'UPDATE haul_request SET ' . implode(', ', $set) . ' WHERE request_id = :rid',
        $params
      );
      $summary['db_ms'] += (microtime(true) - $dbStart) * 1000;
      $summary['updated_requests']++;
      $summary['updated']++;

      if ($newState !== $oldState) {
        $summary['state_transitions']++;
        $dbStart = microtime(true);
        $this->db->insert('ops_event', [
          'corp_id' => $corpId,
          'request_id' => $requestId,
          'contract_id' => $contractId,
          'old_state' => $oldState,
          'new_state' => $newState,
          'acceptor_name' => $acceptorName,
        ]);
        $summary['db_ms'] += (microtime(true) - $dbStart) * 1000;

        $eventKey = $this->eventKeyForState($newState);
        if ($eventKey !== null && $this->webhooks) {
          switch ($newState) {
            case 'PICKED_UP':
              $payload = $this->buildPickupWebhookPayload($request, $contractId, $acceptorId, $acceptorName);
              break;
            case 'DELIVERED':
              $payload = $this->buildDeliveredWebhookPayload($request, $contractId, $acceptorId, $acceptorName);
              break;
            case 'FAILED':
              $payload = $this->buildFailedWebhookPayload($request, $contractId, $acceptorId, $acceptorName);
              break;
            case 'EXPIRED':
              $payload = $this->buildExpiredWebhookPayload($request, $contractId, $acceptorId, $acceptorName);
              break;
            default:
              $payload = [
                'request_id' => $requestId,
                'contract_id' => $contractId,
                'previous_state' => $oldState,
                'contract_state' => $newState,
                'acceptor_id' => $acceptorId,
                'acceptor_name' => $acceptorName,
                'contract_status_esi' => $contractStatus,
              ];
              break;
          }
          $summary['webhook_enqueued'] += $this->webhooks->enqueueUnique(
            $corpId,
            $eventKey,
            $payload,
            'haul_request',
            $requestId,
            $newState
          );
        }

        if ($this->discordEvents) {
          try {
            switch ($newState) {
              case 'PICKED_UP':
                $this->discordEvents->enqueueContractPickedUp($corpId, $requestId, $contractId, [
                  'hauler' => $acceptorName ?? '',
                ]);
                break;
              case 'DELIVERED':
                $this->discordEvents->enqueueContractCompleted($corpId, $requestId, $contractId, [
                  'hauler' => $acceptorName ?? '',
                ]);
                break;
              case 'FAILED':
                $this->discordEvents->enqueueContractFailed($corpId, $requestId, $contractId, [
                  'hauler' => $acceptorName ?? '',
                ]);
                break;
              case 'EXPIRED':
                $this->discordEvents->enqueueContractExpired($corpId, $requestId, $contractId, [
                  'hauler' => $acceptorName ?? '',
                ]);
                break;
            }
          } catch (\Throwable $e) {
            // Ignore Discord event enqueue failures to avoid blocking reconciliation.
          }
        }
      }
      $processedRequests++;

      if ($maxRuntimeSeconds > 0 && (microtime(true) - $startedAt) >= $maxRuntimeSeconds) {
        throw new \RuntimeException("Contracts reconcile exceeded {$maxRuntimeSeconds}s while processing requests.");
      }
      if ($progressCb && $processedRequests > 0 && ($processedRequests % 200 === 0)) {
        $progressCb([
          'label' => sprintf('Contracts reconcile processed %d/%d requests', $processedRequests, $summary['scanned']),
          'stage' => 'contracts_reconcile',
          'processed' => $processedRequests,
          'total' => $summary['scanned'],
        ]);
      }
    }

    $summary['duration_ms'] = (microtime(true) - $startedAt) * 1000;
    if ($summary['stop_reason'] === null) {
      $summary['stop_reason'] = 'completed';
    }
    return $summary;
  }

  private function deriveContractLifecycle(
    int $contractId,
    string $status,
    ?int $acceptorId,
    ?string $dateAccepted,
    ?string $dateCompleted,
    ?string $dateExpired
  ): string {
    if ($contractId <= 0) {
      return 'AWAITING_CONTRACT';
    }

    $normalized = strtolower(trim($status));
    $finished = in_array($normalized, ['finished', 'finished_issuer', 'finished_contractor', 'completed'], true);
    if ($finished) {
      return 'DELIVERED';
    }

    if (in_array($normalized, ['failed', 'cancelled', 'rejected', 'deleted'], true)) {
      return 'FAILED';
    }

    if ($dateExpired) {
      $expiredAt = strtotime($dateExpired);
      if ($expiredAt !== false && $expiredAt <= time()) {
        return 'EXPIRED';
      }
    }

    if ($normalized === 'outstanding' && ($acceptorId === null || $acceptorId === 0)) {
      return 'AVAILABLE';
    }

    if ($acceptorId !== null || $dateAccepted || $normalized === 'in_progress') {
      return 'PICKED_UP';
    }

    return 'AVAILABLE';
  }

  private function eventKeyForState(string $state): ?string
  {
    return match ($state) {
      'PICKED_UP' => 'contract.picked_up',
      'DELIVERED' => 'contract.delivered',
      'FAILED' => 'contract.failed',
      'EXPIRED' => 'contract.expired',
      default => null,
    };
  }

  private function buildContractHash(
    int $contractId,
    string $status,
    ?int $acceptorId,
    ?string $dateAccepted,
    ?string $dateCompleted,
    ?string $dateExpired
  ): string {
    $payload = [
      'contract_id' => $contractId,
      'status' => $status,
      'acceptor_id' => $acceptorId,
      'date_accepted' => $dateAccepted,
      'date_completed' => $dateCompleted,
      'date_expired' => $dateExpired,
    ];
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
  }

  private function buildPickupWebhookPayload(
    array $request,
    int $contractId,
    ?int $acceptorId,
    string $acceptorName
  ): array {
    $fromId = (int)($request['from_location_id'] ?? 0);
    $toId = (int)($request['to_location_id'] ?? 0);
    $fromName = $this->resolveSystemName($fromId, (string)($request['from_location_type'] ?? ''));
    $toName = $this->resolveSystemName($toId, (string)($request['to_location_type'] ?? ''));
    $routeLabel = trim(($fromName !== '' ? $fromName : ('Location #' . $fromId)) . ' → ' . ($toName !== '' ? $toName : ('Location #' . $toId)));

    return $this->webhooks
      ? $this->webhooks->buildContractPickedUpPayload([
        'request_id' => (int)($request['request_id'] ?? 0),
        'request_key' => (string)($request['request_key'] ?? ''),
        'route' => $routeLabel,
        'pickup' => $fromName !== '' ? $fromName : ('Location #' . $fromId),
        'dropoff' => $toName !== '' ? $toName : ('Location #' . $toId),
        'ship_class' => (string)($request['ship_class'] ?? ''),
        'volume_m3' => (float)($request['volume_m3'] ?? 0.0),
        'collateral_isk' => (float)($request['collateral_isk'] ?? 0.0),
        'reward_isk' => (float)($request['reward_isk'] ?? 0.0),
        'contract_id' => $contractId,
        'acceptor_id' => $acceptorId ?? 0,
        'acceptor_name' => $acceptorName,
      ])
      : [];
  }

  private function buildDeliveredWebhookPayload(
    array $request,
    int $contractId,
    ?int $acceptorId,
    string $acceptorName
  ): array {
    return $this->buildContractLifecycleWebhookPayload(
      $request,
      $contractId,
      $acceptorId,
      $acceptorName,
      'delivered'
    );
  }

  private function buildFailedWebhookPayload(
    array $request,
    int $contractId,
    ?int $acceptorId,
    string $acceptorName
  ): array {
    return $this->buildContractLifecycleWebhookPayload(
      $request,
      $contractId,
      $acceptorId,
      $acceptorName,
      'failed'
    );
  }

  private function buildExpiredWebhookPayload(
    array $request,
    int $contractId,
    ?int $acceptorId,
    string $acceptorName
  ): array {
    return $this->buildContractLifecycleWebhookPayload(
      $request,
      $contractId,
      $acceptorId,
      $acceptorName,
      'expired'
    );
  }

  private function buildContractLifecycleWebhookPayload(
    array $request,
    int $contractId,
    ?int $acceptorId,
    string $acceptorName,
    string $state
  ): array {
    $fromId = (int)($request['from_location_id'] ?? 0);
    $toId = (int)($request['to_location_id'] ?? 0);
    $fromName = $this->resolveSystemName($fromId, (string)($request['from_location_type'] ?? ''));
    $toName = $this->resolveSystemName($toId, (string)($request['to_location_type'] ?? ''));
    $routeLabel = trim(($fromName !== '' ? $fromName : ('Location #' . $fromId)) . ' → ' . ($toName !== '' ? $toName : ('Location #' . $toId)));

    $details = [
      'request_id' => (int)($request['request_id'] ?? 0),
      'request_key' => (string)($request['request_key'] ?? ''),
      'route' => $routeLabel,
      'pickup' => $fromName !== '' ? $fromName : ('Location #' . $fromId),
      'dropoff' => $toName !== '' ? $toName : ('Location #' . $toId),
      'ship_class' => (string)($request['ship_class'] ?? ''),
      'volume_m3' => (float)($request['volume_m3'] ?? 0.0),
      'collateral_isk' => (float)($request['collateral_isk'] ?? 0.0),
      'reward_isk' => (float)($request['reward_isk'] ?? 0.0),
      'contract_id' => $contractId,
      'acceptor_id' => $acceptorId ?? 0,
      'acceptor_name' => $acceptorName,
    ];

    if (!$this->webhooks) {
      return [];
    }

    switch ($state) {
      case 'delivered':
        return $this->webhooks->buildContractDeliveredPayload($details);
      case 'failed':
        return $this->webhooks->buildContractFailedPayload($details);
      case 'expired':
        return $this->webhooks->buildContractExpiredPayload($details);
      default:
        return [];
    }
  }

  private function resolveSystemName(int $locationId, string $locationType): string
  {
    if ($locationId <= 0) {
      return '';
    }

    if ($locationType !== 'system') {
      return '';
    }

    return trim((string)$this->db->fetchValue(
      "SELECT system_name FROM map_system WHERE system_id = :id LIMIT 1",
      ['id' => $locationId]
    ));
  }
}
