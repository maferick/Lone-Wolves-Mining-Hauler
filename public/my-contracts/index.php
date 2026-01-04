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
    $hasContractLifecycle = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_lifecycle'");
    $hasContractState = $hasContractLifecycle ? false : (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_state'");
    $lifecycleFilter = '';
    if ($hasContractLifecycle) {
      $lifecycleFilter = " AND (contract_lifecycle IS NULL OR contract_lifecycle NOT IN ('DELIVERED','FAILED','EXPIRED'))";
    } elseif ($hasContractState) {
      $lifecycleFilter = " AND (contract_state IS NULL OR contract_state NOT IN ('DELIVERED','FAILED','EXPIRED'))";
    }
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
          {$lifecycleFilter}
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
    $hasEsiStatus = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'esi_status'");
    $esiStatusSelect = $hasEsiStatus ? 'r.esi_status' : 'r.contract_status_esi AS esi_status';
    $hasContractLifecycle = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'contract_lifecycle'");
    $contractLifecycleSelect = $hasContractLifecycle ? 'r.contract_lifecycle' : 'r.contract_state AS contract_lifecycle';
    $hasMismatchJson = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'mismatch_reason_json'");
    $mismatchSelect = $hasMismatchJson ? 'r.mismatch_reason_json' : 'NULL AS mismatch_reason_json';
    $hasEsiAcceptorName = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'esi_acceptor_name'");
    $acceptorNameSelect = $hasEsiAcceptorName
      ? 'r.esi_acceptor_name'
      : "COALESCE(a_ent.name, CONCAT('Character:', r.contract_acceptor_id)) AS esi_acceptor_name";
    $acceptorNameJoin = $hasEsiAcceptorName
      ? ''
      : "LEFT JOIN eve_entity a_ent ON a_ent.entity_id = r.contract_acceptor_id AND a_ent.entity_type = 'character'";
    $requests = $db->select(
      "SELECT r.request_id, r.status, r.contract_id, r.esi_contract_id, r.contract_status, {$esiStatusSelect},
              {$contractLifecycleSelect}, {$mismatchSelect},
              r.esi_acceptor_id, {$acceptorNameSelect},
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
    $hasEsiStatus = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'esi_status'");
    $esiStatusSelect = $hasEsiStatus ? 'r.esi_status' : 'r.contract_status_esi AS esi_status';
    $hasContractLifecycle = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_lifecycle'");
    $contractLifecycleSelect = $hasContractLifecycle ? 'r.contract_lifecycle' : 'r.contract_state AS contract_lifecycle';
    $hasMismatchJson = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'mismatch_reason_json'");
    $mismatchSelect = $hasMismatchJson ? 'r.mismatch_reason_json' : 'NULL AS mismatch_reason_json';
    $hasEsiAcceptorName = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'esi_acceptor_name'");
    $acceptorNameSelect = $hasEsiAcceptorName
      ? "COALESCE(r.esi_acceptor_name, ce.name, CONCAT('Character:', r.esi_acceptor_id)) AS esi_acceptor_name"
      : "COALESCE(ce.name, CONCAT('Character:', r.contract_acceptor_id)) AS esi_acceptor_name";
    $requests = $db->select(
      "SELECT r.request_id, r.request_key, r.status, r.contract_id, r.esi_contract_id, r.contract_status, {$esiStatusSelect},
              {$contractLifecycleSelect}, {$mismatchSelect},
              r.esi_acceptor_id, {$acceptorNameSelect},
              r.from_location_id, r.to_location_id, r.volume_m3, r.reward_isk, r.created_at,
              fs.system_name AS from_name, ts.system_name AS to_name
         FROM haul_request r
         LEFT JOIN eve_system fs ON fs.system_id = r.from_location_id AND r.from_location_type = 'system'
         LEFT JOIN eve_system ts ON ts.system_id = r.to_location_id AND r.to_location_type = 'system'
         LEFT JOIN eve_entity ce ON ce.entity_id = COALESCE(r.esi_acceptor_id, r.contract_acceptor_id) AND ce.entity_type = 'character'
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
