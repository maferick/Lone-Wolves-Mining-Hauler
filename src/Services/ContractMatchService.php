<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

final class ContractMatchService
{
  private const OPEN_CONTRACT_STATUSES = [
    'outstanding',
    'in_progress',
  ];

  private const COMPLETED_CONTRACT_STATUSES = [
    'finished',
    'finished_issuer',
    'completed',
  ];

  public function __construct(
    private Db $db,
    private array $config,
    private ?DiscordWebhookService $webhooks = null
  ) {
  }

  public function matchOpenRequests(int $corpId): array
  {
    $requests = $this->db->select(
      "SELECT request_id, request_key, corp_id, from_location_id, to_location_id, volume_m3, collateral_isk, reward_isk,
              ship_class, route_policy, route_profile, contract_hint_text, contract_id, contract_status, status
         FROM haul_request
        WHERE corp_id = :cid
          AND status IN ('requested','awaiting_contract','contract_linked','contract_mismatch','in_queue','in_progress')",
      ['cid' => $corpId]
    );

    $rewardTolerance = $this->loadRewardTolerance($corpId);
    $summary = [
      'corp_id' => $corpId,
      'checked' => 0,
      'matched' => 0,
      'mismatched' => 0,
      'completed' => 0,
      'linked_notifications' => 0,
      'timestamp' => gmdate('c'),
    ];

    foreach ($requests as $request) {
      $summary['checked']++;
      $requestId = (int)($request['request_id'] ?? 0);
      if ($requestId <= 0) {
        continue;
      }

      $contractId = (int)($request['contract_id'] ?? 0);
      if ($contractId > 0) {
        $result = $this->validateLinkedRequest($request, $rewardTolerance);
        $summary['matched'] += (int)($result['matched'] ?? 0);
        $summary['mismatched'] += (int)($result['mismatched'] ?? 0);
        $summary['completed'] += (int)($result['completed'] ?? 0);
        $summary['linked_notifications'] += (int)($result['linked_notifications'] ?? 0);
        continue;
      }

      $match = $this->findMatchingContract($corpId, $request, $rewardTolerance);
      if (!$match) {
        continue;
      }

      $update = $this->applyMatch($request, $match['contract'], $match['validation']);
      if (!empty($update['matched'])) {
        $summary['matched']++;
      }
      if (!empty($update['linked_notifications'])) {
        $summary['linked_notifications'] += (int)$update['linked_notifications'];
      }
    }

    return $summary;
  }

  private function validateLinkedRequest(array $request, array $rewardTolerance): array
  {
    $corpId = (int)($request['corp_id'] ?? 0);
    $contractId = (int)($request['contract_id'] ?? 0);
    $result = [
      'matched' => 0,
      'mismatched' => 0,
      'completed' => 0,
      'linked_notifications' => 0,
    ];

    $contract = $this->fetchContract($corpId, $contractId);
    if (!$contract) {
      $mismatch = [
        'contract_id' => $contractId,
        'mismatches' => [
          'contract' => [
            'expected' => 'contract in esi_corp_contract',
            'actual' => 'not found',
          ],
        ],
      ];
      $this->markMismatch($request, $mismatch, [], 'missing');
      $result['mismatched']++;
      return $result;
    }

    $contractStatus = (string)($contract['status'] ?? '');
    if ($this->isContractCompleted($contractStatus)) {
      $this->markCompleted($request, $contract, $rewardTolerance);
      $result['completed']++;
      return $result;
    }

    $validation = $this->evaluateContract($request, $contract, $rewardTolerance);
    if (!empty($validation['matched'])) {
      $update = $this->applyMatch($request, $contract, $validation);
      $result['matched']++;
      $result['linked_notifications'] += (int)($update['linked_notifications'] ?? 0);
      return $result;
    }

    $this->markMismatch($request, $validation['mismatch'] ?? [], $validation['flags'] ?? [], (string)($contract['status'] ?? 'unknown'));
    $result['mismatched']++;
    return $result;
  }

  private function findMatchingContract(int $corpId, array $request, array $rewardTolerance): ?array
  {
    $contracts = $this->db->select(
      "SELECT c.contract_id, c.type, c.status, c.start_location_id, c.end_location_id,
              c.volume_m3, c.collateral_isk, c.reward_isk, c.title, c.raw_json,
              COALESCE(ms.system_id, es.system_id, est.system_id, 0) AS start_system_id,
              COALESCE(ms2.system_id, es2.system_id, est2.system_id, 0) AS end_system_id
         FROM esi_corp_contract c
         LEFT JOIN map_system ms ON ms.system_id = c.start_location_id
         LEFT JOIN eve_station es ON es.station_id = c.start_location_id
         LEFT JOIN eve_structure est ON est.structure_id = c.start_location_id
         LEFT JOIN map_system ms2 ON ms2.system_id = c.end_location_id
         LEFT JOIN eve_station es2 ON es2.station_id = c.end_location_id
         LEFT JOIN eve_structure est2 ON est2.structure_id = c.end_location_id
        WHERE c.corp_id = :cid
          AND c.type = 'courier'
          AND c.status IN ('outstanding','in_progress')
          AND COALESCE(ms.system_id, es.system_id, est.system_id, 0) = :from_system
          AND COALESCE(ms2.system_id, es2.system_id, est2.system_id, 0) = :to_system
        ORDER BY c.date_issued DESC",
      [
        'cid' => $corpId,
        'from_system' => (int)($request['from_location_id'] ?? 0),
        'to_system' => (int)($request['to_location_id'] ?? 0),
      ]
    );

    foreach ($contracts as $contract) {
      $validation = $this->evaluateContract($request, $contract, $rewardTolerance);
      if (!empty($validation['matched'])) {
        return ['contract' => $contract, 'validation' => $validation];
      }
    }

    return null;
  }

  private function evaluateContract(array $request, array $contract, array $rewardTolerance): array
  {
    $flags = [];
    $mismatches = [];

    $flags['type'] = ((string)($contract['type'] ?? '')) === 'courier';
    if (!$flags['type']) {
      $mismatches['type'] = [
        'expected' => 'courier',
        'actual' => (string)($contract['type'] ?? 'unknown'),
      ];
    }

    $status = (string)($contract['status'] ?? '');
    $flags['status'] = in_array($status, self::OPEN_CONTRACT_STATUSES, true);
    if (!$flags['status']) {
      $mismatches['status'] = [
        'expected' => implode('|', self::OPEN_CONTRACT_STATUSES),
        'actual' => $status,
      ];
    }

    $startSystemId = (int)($contract['start_system_id'] ?? $this->resolveSystemId((int)($contract['start_location_id'] ?? 0)));
    $endSystemId = (int)($contract['end_system_id'] ?? $this->resolveSystemId((int)($contract['end_location_id'] ?? 0)));
    $flags['start_system'] = $startSystemId === (int)($request['from_location_id'] ?? 0);
    if (!$flags['start_system']) {
      $mismatches['start_system'] = [
        'expected' => (int)($request['from_location_id'] ?? 0),
        'actual' => $startSystemId,
      ];
    }
    $flags['end_system'] = $endSystemId === (int)($request['to_location_id'] ?? 0);
    if (!$flags['end_system']) {
      $mismatches['end_system'] = [
        'expected' => (int)($request['to_location_id'] ?? 0),
        'actual' => $endSystemId,
      ];
    }

    $requestVolume = (float)($request['volume_m3'] ?? 0.0);
    $contractVolume = (float)($contract['volume_m3'] ?? 0.0);
    $flags['volume'] = abs($contractVolume - $requestVolume) <= 1.0;
    if (!$flags['volume']) {
      $mismatches['volume_m3'] = [
        'expected' => $requestVolume,
        'actual' => $contractVolume,
      ];
    }

    $requestCollateral = (float)($request['collateral_isk'] ?? 0.0);
    $contractCollateral = (float)($contract['collateral_isk'] ?? 0.0);
    $flags['collateral'] = $this->compareMoney($contractCollateral, $requestCollateral, 0.0);
    if (!$flags['collateral']) {
      $mismatches['collateral_isk'] = [
        'expected' => $requestCollateral,
        'actual' => $contractCollateral,
      ];
    }

    $requestReward = (float)($request['reward_isk'] ?? 0.0);
    $contractReward = (float)($contract['reward_isk'] ?? 0.0);
    $flags['reward'] = $this->compareMoney($contractReward, $requestReward, $this->rewardToleranceValue($requestReward, $rewardTolerance));
    if (!$flags['reward']) {
      $mismatches['reward_isk'] = [
        'expected' => $requestReward,
        'actual' => $contractReward,
      ];
    }

    $hint = trim((string)($request['contract_hint_text'] ?? ''));
    $description = $this->contractDescription($contract);
    $flags['description'] = $hint !== '' && $description !== '' && str_contains($description, $hint);
    if (!$flags['description']) {
      $mismatches['description'] = [
        'expected_substring' => $hint,
        'actual' => $description,
      ];
    }

    $matched = !in_array(false, $flags, true);
    return [
      'matched' => $matched,
      'flags' => $flags,
      'mismatch' => [
        'contract_id' => (int)($contract['contract_id'] ?? 0),
        'mismatches' => $mismatches,
      ],
    ];
  }

  private function applyMatch(array $request, array $contract, array $validation): array
  {
    $requestId = (int)($request['request_id'] ?? 0);
    $previousStatus = (string)($request['status'] ?? '');
    $contractStatus = (string)($contract['status'] ?? 'unknown');
    $newStatus = $this->isContractCompleted($contractStatus) ? 'completed' : 'contract_linked';

    $this->db->execute(
      "UPDATE haul_request
          SET contract_id = :contract_id,
              contract_status = :contract_status,
              status = :status,
              contract_matched_at = UTC_TIMESTAMP(),
              contract_validation_json = :validation_json,
              mismatch_reason_json = NULL,
              updated_at = UTC_TIMESTAMP()
        WHERE request_id = :rid",
      [
        'contract_id' => (int)($contract['contract_id'] ?? 0),
        'contract_status' => $contractStatus,
        'status' => $newStatus,
        'validation_json' => Db::jsonEncode($validation['flags'] ?? []),
        'rid' => $requestId,
      ]
    );

    $notifications = 0;
    if ($newStatus === 'contract_linked' && $previousStatus !== 'contract_linked') {
      $notifications = $this->notifyContractLinked($request, $contract);
    }

    return [
      'matched' => true,
      'linked_notifications' => $notifications,
    ];
  }

  private function markMismatch(array $request, array $mismatch, array $flags, string $contractStatus): void
  {
    $requestId = (int)($request['request_id'] ?? 0);
    $this->db->execute(
      "UPDATE haul_request
          SET status = 'contract_mismatch',
              contract_status = :contract_status,
              contract_validation_json = :validation_json,
              mismatch_reason_json = :mismatch_json,
              updated_at = UTC_TIMESTAMP()
        WHERE request_id = :rid",
      [
        'contract_status' => $contractStatus,
        'validation_json' => Db::jsonEncode($flags),
        'mismatch_json' => Db::jsonEncode($mismatch),
        'rid' => $requestId,
      ]
    );
  }

  private function markCompleted(array $request, array $contract, array $rewardTolerance): void
  {
    $validation = $this->evaluateContract($request, $contract, $rewardTolerance);
    $this->db->execute(
      "UPDATE haul_request
          SET status = 'completed',
              contract_status = :contract_status,
              contract_validation_json = :validation_json,
              mismatch_reason_json = NULL,
              updated_at = UTC_TIMESTAMP()
        WHERE request_id = :rid",
      [
        'contract_status' => (string)($contract['status'] ?? 'unknown'),
        'validation_json' => Db::jsonEncode($validation['flags'] ?? []),
        'rid' => (int)($request['request_id'] ?? 0),
      ]
    );
  }

  private function fetchContract(int $corpId, int $contractId): ?array
  {
    $row = $this->db->one(
      "SELECT c.contract_id, c.type, c.status, c.start_location_id, c.end_location_id,
              c.volume_m3, c.collateral_isk, c.reward_isk, c.title, c.raw_json,
              COALESCE(ms.system_id, es.system_id, est.system_id, 0) AS start_system_id,
              COALESCE(ms2.system_id, es2.system_id, est2.system_id, 0) AS end_system_id
         FROM esi_corp_contract c
         LEFT JOIN map_system ms ON ms.system_id = c.start_location_id
         LEFT JOIN eve_station es ON es.station_id = c.start_location_id
         LEFT JOIN eve_structure est ON est.structure_id = c.start_location_id
         LEFT JOIN map_system ms2 ON ms2.system_id = c.end_location_id
         LEFT JOIN eve_station es2 ON es2.station_id = c.end_location_id
         LEFT JOIN eve_structure est2 ON est2.structure_id = c.end_location_id
        WHERE c.corp_id = :cid AND c.contract_id = :contract_id
        LIMIT 1",
      [
        'cid' => $corpId,
        'contract_id' => $contractId,
      ]
    );

    return $row ?: null;
  }

  private function resolveSystemId(int $locationId): int
  {
    if ($locationId <= 0) {
      return 0;
    }
    $row = $this->db->one(
      "SELECT system_id FROM map_system WHERE system_id = :id LIMIT 1",
      ['id' => $locationId]
    );
    if ($row) {
      return (int)$row['system_id'];
    }
    $row = $this->db->one(
      "SELECT system_id FROM eve_station WHERE station_id = :id LIMIT 1",
      ['id' => $locationId]
    );
    if ($row) {
      return (int)$row['system_id'];
    }
    $row = $this->db->one(
      "SELECT system_id FROM eve_structure WHERE structure_id = :id LIMIT 1",
      ['id' => $locationId]
    );
    if ($row) {
      return (int)$row['system_id'];
    }
    return 0;
  }

  private function contractDescription(array $contract): string
  {
    $title = trim((string)($contract['title'] ?? ''));
    $decoded = [];
    if (!empty($contract['raw_json'])) {
      $decoded = Db::jsonDecode((string)$contract['raw_json'], []);
    }
    $desc = trim((string)($decoded['description'] ?? ''));
    if ($desc !== '') {
      return $desc;
    }
    if ($title !== '') {
      return $title;
    }
    return trim((string)($decoded['title'] ?? ''));
  }

  private function isContractCompleted(string $status): bool
  {
    return in_array($status, self::COMPLETED_CONTRACT_STATUSES, true);
  }

  private function compareMoney(float $actual, float $expected, float $tolerance): bool
  {
    $epsilon = 0.01;
    return abs($actual - $expected) <= ($tolerance + $epsilon);
  }

  private function loadRewardTolerance(int $corpId): array
  {
    $row = $this->db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'contract.reward_tolerance' LIMIT 1",
      ['cid' => $corpId]
    );
    $decoded = $row && !empty($row['setting_json'])
      ? Db::jsonDecode((string)$row['setting_json'], [])
      : [];

    return [
      'type' => (string)($decoded['type'] ?? 'percent'),
      'value' => (float)($decoded['value'] ?? 0.0),
    ];
  }

  private function rewardToleranceValue(float $expectedReward, array $rewardTolerance): float
  {
    if (($rewardTolerance['type'] ?? '') === 'flat') {
      return (float)($rewardTolerance['value'] ?? 0.0);
    }
    $percent = (float)($rewardTolerance['value'] ?? 0.0);
    return $expectedReward * $percent;
  }

  private function notifyContractLinked(array $request, array $contract): int
  {
    if (!$this->webhooks) {
      return 0;
    }
    $fromId = (int)($request['from_location_id'] ?? 0);
    $toId = (int)($request['to_location_id'] ?? 0);
    $fromName = (string)$this->db->fetchValue(
      "SELECT system_name FROM map_system WHERE system_id = :id LIMIT 1",
      ['id' => $fromId]
    );
    $toName = (string)$this->db->fetchValue(
      "SELECT system_name FROM map_system WHERE system_id = :id LIMIT 1",
      ['id' => $toId]
    );
    $routeLabel = trim(($fromName !== '' ? $fromName : ('System #' . $fromId)) . ' → ' . ($toName !== '' ? $toName : ('System #' . $toId)));
    $issuerId = (int)($contract['issuer_id'] ?? 0);
    $issuerName = $issuerId > 0
      ? (string)$this->db->fetchValue(
        "SELECT name FROM eve_entity WHERE entity_id = :id AND entity_type = 'character' LIMIT 1",
        ['id' => $issuerId]
      )
      : '';

    $payload = $this->webhooks->buildContractLinkedPayload([
      'request_id' => (int)($request['request_id'] ?? 0),
      'request_key' => (string)($request['request_key'] ?? ''),
      'route' => $routeLabel,
      'pickup' => $fromName !== '' ? $fromName : ('System #' . $fromId),
      'dropoff' => $toName !== '' ? $toName : ('System #' . $toId),
      'ship_class' => (string)($request['ship_class'] ?? ''),
      'volume_m3' => (float)($request['volume_m3'] ?? 0.0),
      'collateral_isk' => (float)($request['collateral_isk'] ?? 0.0),
      'price_isk' => (float)($request['reward_isk'] ?? 0.0),
      'contract_id' => (int)($contract['contract_id'] ?? 0),
      'issuer_id' => $issuerId,
      'issuer_name' => $issuerName,
    ]);

    return $this->webhooks->enqueueContractLinked((int)($request['corp_id'] ?? 0), $payload);
  }
}
