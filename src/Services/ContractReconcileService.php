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
    private ?DiscordWebhookService $webhooks = null
  ) {
  }

  public function reconcile(int $corpId): array
  {
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
    ];

    $requests = $this->db->select(
      "SELECT request_id, contract_id, contract_status_esi, contract_state,
              contract_acceptor_id, contract_acceptor_name,
              date_accepted, date_completed, date_expired
         FROM haul_request
        WHERE corp_id = :cid
          AND contract_id IS NOT NULL",
      ['cid' => $corpId]
    );

    $summary['linked_requests'] = count($requests);
    $summary['scanned'] = $summary['linked_requests'];
    if ($requests === []) {
      return $summary;
    }

    $contractIds = [];
    foreach ($requests as $request) {
      $contractId = (int)($request['contract_id'] ?? 0);
      if ($contractId > 0) {
        $contractIds[$contractId] = true;
      }
    }

    if ($contractIds === []) {
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

    $publicData = new EvePublicDataService($this->db, $this->config, $this->esi);

    foreach ($requests as $request) {
      $requestId = (int)($request['request_id'] ?? 0);
      $contractId = (int)($request['contract_id'] ?? 0);
      if ($requestId <= 0 || $contractId <= 0) {
        $summary['skipped_requests']++;
        $summary['skipped']++;
        continue;
      }

      if (!isset($contractsById[$contractId])) {
        $summary['missing_contracts']++;
        $summary['not_found']++;
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
        $acceptorName = (string)$this->db->fetchValue(
          "SELECT name FROM eve_entity WHERE entity_id = :id AND entity_type = 'character' LIMIT 1",
          ['id' => $acceptorId]
        );
        if ($acceptorName === '') {
          try {
            $character = $publicData->character($acceptorId);
            $acceptorName = trim((string)($character['name'] ?? ''));
          } catch (\Throwable $e) {
            $summary['errors']++;
          }
        }
        if ($acceptorName === '') {
          $acceptorName = (string)($request['contract_acceptor_name'] ?? '');
        }
        if ($acceptorName === '') {
          $acceptorName = 'Character #' . $acceptorId;
        }
      }

      $newState = $this->deriveContractState($contractStatus, $acceptorId, $dateAccepted, $dateCompleted, $dateExpired);
      $oldState = (string)($request['contract_state'] ?? '');

      $changes = [];
      if ((string)($request['contract_status_esi'] ?? '') !== $contractStatus) {
        $changes['contract_status_esi'] = $contractStatus;
      }

      $currentAcceptor = $request['contract_acceptor_id'] ?? null;
      $currentAcceptor = $currentAcceptor !== null ? (int)$currentAcceptor : null;
      if ($currentAcceptor !== $acceptorId) {
        $changes['contract_acceptor_id'] = $acceptorId;
      }

      if ($acceptorId === null) {
        if (!empty($request['contract_acceptor_name'])) {
          $changes['contract_acceptor_name'] = null;
        }
      } elseif ($acceptorName !== '' && (string)($request['contract_acceptor_name'] ?? '') !== $acceptorName) {
        $changes['contract_acceptor_name'] = $acceptorName;
      }

      if ((string)($request['date_accepted'] ?? '') !== (string)($dateAccepted ?? '')) {
        $changes['date_accepted'] = $dateAccepted;
      }
      if ((string)($request['date_completed'] ?? '') !== (string)($dateCompleted ?? '')) {
        $changes['date_completed'] = $dateCompleted;
      }
      if ((string)($request['date_expired'] ?? '') !== (string)($dateExpired ?? '')) {
        $changes['date_expired'] = $dateExpired;
      }

      if ($newState !== $oldState) {
        $changes['contract_state'] = $newState;
      }

      if ($changes === []) {
        $summary['skipped_requests']++;
        $summary['skipped']++;
        continue;
      }

      $set = [];
      $params = ['rid' => $requestId];
      foreach ($changes as $column => $value) {
        $set[] = "{$column} = :{$column}";
        $params[$column] = $value;
      }
      $set[] = 'updated_at = UTC_TIMESTAMP()';

      $this->db->execute(
        'UPDATE haul_request SET ' . implode(', ', $set) . ' WHERE request_id = :rid',
        $params
      );
      $summary['updated_requests']++;
      $summary['updated']++;

      if ($newState !== $oldState) {
        $summary['state_transitions']++;
        $this->db->insert('ops_event', [
          'corp_id' => $corpId,
          'request_id' => $requestId,
          'contract_id' => $contractId,
          'old_state' => $oldState,
          'new_state' => $newState,
          'acceptor_name' => $acceptorName,
        ]);

        $eventKey = $this->eventKeyForState($newState);
        if ($eventKey !== null && $this->webhooks) {
          $payload = [
            'request_id' => $requestId,
            'contract_id' => $contractId,
            'previous_state' => $oldState,
            'contract_state' => $newState,
            'acceptor_id' => $acceptorId,
            'acceptor_name' => $acceptorName,
            'contract_status_esi' => $contractStatus,
          ];
          $summary['webhook_enqueued'] += $this->webhooks->enqueue($corpId, $eventKey, $payload);
        }
      }
    }

    return $summary;
  }

  private function deriveContractState(
    string $status,
    ?int $acceptorId,
    ?string $dateAccepted,
    ?string $dateCompleted,
    ?string $dateExpired
  ): string {
    $normalized = strtolower(trim($status));
    $finished = in_array($normalized, ['finished', 'finished_issuer', 'finished_contractor', 'completed'], true);
    if ($finished) {
      return 'DELIVERED';
    }

    if (in_array($normalized, ['failed', 'cancelled', 'rejected', 'deleted'], true)) {
      return 'FAILED';
    }

    $expiredByStatus = $normalized === 'expired';
    $expiredByTime = false;
    if ($dateExpired) {
      $expiredAt = strtotime($dateExpired);
      if ($expiredAt !== false && $expiredAt <= time()) {
        $expiredByTime = true;
      }
    }
    if ($expiredByStatus || $expiredByTime) {
      return 'EXPIRED';
    }

    if ($acceptorId !== null || $dateAccepted) {
      return 'PICKED_UP';
    }

    if ($normalized === 'outstanding') {
      return 'AVAILABLE';
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
}
