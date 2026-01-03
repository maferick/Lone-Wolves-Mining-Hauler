<?php
declare(strict_types=1);

// Standalone my contracts endpoint (works even if routing rules are bypassed)
require_once __DIR__ . '/../../src/bootstrap.php';

$appName = $config['app']['name'];
$title = $appName . ' • My Contracts';
$basePathForViews = rtrim((string)($config['app']['base_path'] ?? ''), '/');

\App\Auth\Auth::requireLogin($authCtx);

$userId = (int)($authCtx['user_id'] ?? 0);
$corpId = (int)($authCtx['corp_id'] ?? ($config['corp']['id'] ?? 0));
$requests = [];
$requestsAvailable = false;
$queuePositions = [];
$queueTotal = 0;

if (($health['db'] ?? false) && $db !== null && $userId > 0) {
  $hasHaulRequest = (bool)$db->fetchValue("SHOW TABLES LIKE 'haul_request'");
  $hasRequestView = (bool)$db->fetchValue("SHOW FULL TABLES LIKE 'v_haul_request_display'");

  if ($hasHaulRequest && $corpId > 0) {
    $queueStatuses = [
      'requested',
      'awaiting_contract',
      'contract_linked',
      'contract_mismatch',
      'in_queue',
      'draft',
      'quoted',
      'submitted',
      'posted',
    ];
    $statusPlaceholders = implode(',', array_fill(0, count($queueStatuses), '?'));
    $queueRows = $db->select(
      "SELECT request_id
         FROM haul_request
        WHERE corp_id = ?
          AND status IN ({$statusPlaceholders})
        ORDER BY created_at ASC, request_id ASC",
      array_merge([$corpId], $queueStatuses)
    );
    $position = 1;
    foreach ($queueRows as $row) {
      $rid = (int)($row['request_id'] ?? 0);
      if ($rid > 0) {
        $queuePositions[$rid] = $position;
        $position++;
      }
    }
    $queueTotal = count($queueRows);
  }

  if ($hasRequestView) {
    $hasContractStatusEsi = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'contract_status_esi'");
    $contractStatusEsiSelect = $hasContractStatusEsi ? 'r.contract_status_esi' : 'NULL AS contract_status_esi';
    $hasContractState = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'contract_state'");
    $contractStateSelect = $hasContractState ? 'r.contract_state' : 'NULL AS contract_state';
    $hasMismatchJson = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'mismatch_reason_json'");
    $mismatchSelect = $hasMismatchJson ? 'r.mismatch_reason_json' : 'NULL AS mismatch_reason_json';
    $hasContractAcceptorName = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'contract_acceptor_name'");
    $acceptorNameSelect = $hasContractAcceptorName
      ? 'r.contract_acceptor_name'
      : "COALESCE(a_ent.name, CONCAT('Character:', r.contract_acceptor_id)) AS contract_acceptor_name";
    $acceptorNameJoin = $hasContractAcceptorName
      ? ''
      : "LEFT JOIN eve_entity a_ent ON a_ent.entity_id = r.contract_acceptor_id AND a_ent.entity_type = 'character'";
    $requests = $db->select(
      "SELECT r.request_id, r.status, r.contract_id, r.contract_status, {$contractStatusEsiSelect},
              {$contractStateSelect}, {$mismatchSelect},
              r.contract_acceptor_id, {$acceptorNameSelect},
              COALESCE(fs.system_name, r.from_name) AS from_name,
              COALESCE(ts.system_name, r.to_name) AS to_name,
              r.volume_m3, r.reward_isk, r.created_at, hr.request_key
         FROM v_haul_request_display r
         JOIN haul_request hr ON hr.request_id = r.request_id
         LEFT JOIN eve_system fs ON fs.system_id = r.from_location_id AND r.from_location_type = 'system'
         LEFT JOIN eve_system ts ON ts.system_id = r.to_location_id AND r.to_location_type = 'system'
         {$acceptorNameJoin}
        WHERE r.corp_id = ?
          AND hr.requester_user_id = ?
        ORDER BY r.created_at DESC
        LIMIT 50",
      [$corpId, $userId]
    );
  } elseif ($hasHaulRequest) {
    $hasContractStatusEsi = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_status_esi'");
    $contractStatusEsiSelect = $hasContractStatusEsi ? 'r.contract_status_esi' : 'NULL AS contract_status_esi';
    $hasContractState = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_state'");
    $contractStateSelect = $hasContractState ? 'r.contract_state' : 'NULL AS contract_state';
    $hasMismatchJson = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'mismatch_reason_json'");
    $mismatchSelect = $hasMismatchJson ? 'r.mismatch_reason_json' : 'NULL AS mismatch_reason_json';
    $hasContractAcceptorName = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_acceptor_name'");
    $acceptorNameSelect = $hasContractAcceptorName
      ? "COALESCE(r.contract_acceptor_name, ce.name, CONCAT('Character:', r.contract_acceptor_id)) AS contract_acceptor_name"
      : "COALESCE(ce.name, CONCAT('Character:', r.contract_acceptor_id)) AS contract_acceptor_name";
    $requests = $db->select(
      "SELECT r.request_id, r.request_key, r.status, r.contract_id, r.contract_status, {$contractStatusEsiSelect},
              {$contractStateSelect}, {$mismatchSelect},
              r.contract_acceptor_id, {$acceptorNameSelect},
              r.from_location_id, r.to_location_id, r.volume_m3, r.reward_isk, r.created_at,
              fs.system_name AS from_name, ts.system_name AS to_name,
         FROM haul_request r
         LEFT JOIN eve_system fs ON fs.system_id = r.from_location_id AND r.from_location_type = 'system'
         LEFT JOIN eve_system ts ON ts.system_id = r.to_location_id AND r.to_location_type = 'system'
         LEFT JOIN eve_entity ce ON ce.entity_id = r.contract_acceptor_id AND ce.entity_type = 'character'
        WHERE r.corp_id = ?
          AND r.requester_user_id = ?
        ORDER BY r.created_at DESC
        LIMIT 50",
      [$corpId, $userId]
    );
  }

  if ($requests) {
    $requestsAvailable = true;
  }
}

require __DIR__ . '/../../src/Views/my_contracts.php';
