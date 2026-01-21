<?php
declare(strict_types=1);

// Standalone operations endpoint (works even if routing rules are bypassed)
require_once __DIR__ . '/../../src/bootstrap.php';

use App\Db\Db;

$appName = $config['app']['name'];
$title = $appName . ' • Operations';
\App\Auth\Auth::requireLogin($authCtx);
\App\Auth\Auth::requireAccess($authCtx, 'operations');
$queueStats = [
  'outstanding' => 0,
  'in_progress' => 0,
  'delivered' => 0,
  'failed' => 0,
];
$requests = [];
$haulers = [];
$requestsAvailable = false;
$dbOk = $health['db'] ?? false;
$showDispatchSections = true;

$canViewOps = !empty($authCtx['user_id']) && \App\Auth\Auth::can($authCtx, 'haul.request.read');
$canAssignOps = !empty($authCtx['user_id']) && \App\Auth\Auth::can($authCtx, 'haul.assign');
$corpId = (int)($authCtx['corp_id'] ?? ($config['corp']['id'] ?? 0));

if ($dbOk && $db !== null && $corpId > 0) {
  $settingRow = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'operations.dispatch_sections' LIMIT 1",
    ['cid' => $corpId]
  );
  if ($settingRow && !empty($settingRow['setting_json'])) {
    $decoded = Db::jsonDecode((string)$settingRow['setting_json'], []);
    if (is_array($decoded) && array_key_exists('show_dispatch', $decoded)) {
      $showDispatchSections = (bool)$decoded['show_dispatch'];
    }
  }
}

if ($dbOk && $db !== null && $canViewOps && $corpId > 0) {
  $hasHaulRequest = (bool)$db->fetchValue("SHOW TABLES LIKE 'haul_request'");
  $hasRequestView = (bool)$db->fetchValue("SHOW FULL TABLES LIKE 'v_haul_request_display'");

  if ($hasHaulRequest) {
    $hasContractLifecycle = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_lifecycle'");
    $hasContractState = $hasContractLifecycle ? false : (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_state'");
    $contractLifecycleColumn = null;
    if ($hasContractLifecycle) {
      $contractLifecycleColumn = 'contract_lifecycle';
    } elseif ($hasContractState) {
      $contractLifecycleColumn = 'contract_state';
    }
    $activeLifecycleFilter = $contractLifecycleColumn
      ? " AND ({$contractLifecycleColumn} IS NULL OR {$contractLifecycleColumn} NOT IN ('FAILED','EXPIRED','DELIVERED'))"
      : '';
    $deliveredCase = $contractLifecycleColumn
      ? "SUM(CASE WHEN {$contractLifecycleColumn} = 'DELIVERED' THEN 1 ELSE 0 END) AS delivered"
      : "SUM(CASE WHEN status IN ('completed','delivered') THEN 1 ELSE 0 END) AS delivered";
    $failedCase = $contractLifecycleColumn
      ? "SUM(CASE WHEN {$contractLifecycleColumn} IN ('FAILED','EXPIRED') THEN 1 ELSE 0 END) AS failed"
      : "SUM(CASE WHEN status IN ('cancelled','expired','rejected','failed') THEN 1 ELSE 0 END) AS failed";
    $statsRow = $db->one(
      "SELECT
          SUM(CASE WHEN status IN ('requested','awaiting_contract','contract_linked','contract_mismatch','in_queue','draft','quoted','submitted','posted'){$activeLifecycleFilter} THEN 1 ELSE 0 END) AS outstanding,
          SUM(CASE WHEN status IN ('in_progress','accepted','in_transit'){$activeLifecycleFilter} THEN 1 ELSE 0 END) AS in_progress,
          {$deliveredCase},
          {$failedCase}
        FROM haul_request
       WHERE corp_id = :cid",
      ['cid' => $corpId]
    );
    if ($statsRow) {
      $queueStats['outstanding'] = (int)($statsRow['outstanding'] ?? 0);
      $queueStats['in_progress'] = (int)($statsRow['in_progress'] ?? 0);
      $queueStats['delivered'] = (int)($statsRow['delivered'] ?? 0);
      $queueStats['failed'] = (int)($statsRow['failed'] ?? 0);
    }
  }

  if ($hasRequestView) {
    $hasRequestKey = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'request_key'");
    $requestKeySelect = $hasRequestKey ? 'r.request_key' : 'hr.request_key AS request_key';
    $requestKeyJoin = $hasRequestKey ? '' : 'LEFT JOIN haul_request hr ON hr.request_id = r.request_id';
    $hasValidationJson = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'contract_validation_json'");
    $validationSelect = $hasValidationJson ? 'r.contract_validation_json' : 'NULL AS contract_validation_json';
    $hasMismatchJson = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'mismatch_reason_json'");
    $mismatchSelect = $hasMismatchJson ? 'r.mismatch_reason_json' : 'NULL AS mismatch_reason_json';
    $hasEsiContractId = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'esi_contract_id'");
    $esiContractIdSelect = $hasEsiContractId ? 'r.esi_contract_id' : 'r.contract_id AS esi_contract_id';
    $hasEsiStatus = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'esi_status'");
    $esiStatusSelect = $hasEsiStatus ? 'r.esi_status' : 'r.contract_status_esi AS esi_status';
    $hasContractStatusEsi = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'contract_status_esi'");
    $contractStatusEsiSelect = $hasContractStatusEsi ? 'r.contract_status_esi' : 'NULL AS contract_status_esi';
    $hasContractLifecycle = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'contract_lifecycle'");
    $contractLifecycleSelect = $hasContractLifecycle ? 'r.contract_lifecycle' : 'NULL AS contract_lifecycle';
    $hasContractState = false;
    if (!$hasContractLifecycle) {
      $hasContractState = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'contract_state'");
      if ($hasContractState) {
        $contractLifecycleSelect = 'r.contract_state AS contract_lifecycle';
      }
    }
    $contractLifecycleColumn = null;
    if ($hasContractLifecycle) {
      $contractLifecycleColumn = 'r.contract_lifecycle';
    } elseif (!empty($hasContractState)) {
      $contractLifecycleColumn = 'r.contract_state';
    }
    $contractLifecycleFilter = $contractLifecycleColumn
      ? " AND ({$contractLifecycleColumn} IS NULL OR {$contractLifecycleColumn} NOT IN ('FAILED','EXPIRED','DELIVERED'))"
      : " AND r.status NOT IN ('completed','delivered','cancelled','expired','rejected','failed')";
    $hasEsiAcceptorId = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'esi_acceptor_id'");
    $esiAcceptorIdSelect = $hasEsiAcceptorId ? 'r.esi_acceptor_id' : 'r.contract_acceptor_id AS esi_acceptor_id';
    $hasEsiAcceptorName = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'esi_acceptor_name'");
    $esiAcceptorNameSelect = $hasEsiAcceptorName ? 'r.esi_acceptor_name' : 'r.contract_acceptor_name AS esi_acceptor_name';
    $hasOpsAssigneeId = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'ops_assignee_id'");
    $opsAssigneeIdSelect = $hasOpsAssigneeId ? 'r.ops_assignee_id' : 'a.hauler_user_id AS ops_assignee_id';
    $hasOpsAssigneeName = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'ops_assignee_name'");
    $opsAssigneeNameSelect = $hasOpsAssigneeName ? 'r.ops_assignee_name' : 'u.display_name AS ops_assignee_name';
    $hasOpsStatus = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'ops_status'");
    $opsStatusSelect = $hasOpsStatus ? 'r.ops_status' : 'NULL AS ops_status';
    $requests = $db->select(
      "SELECT r.request_id, {$requestKeySelect}, r.status, {$esiContractIdSelect} AS esi_contract_id, r.contract_status,
              {$esiStatusSelect} AS esi_status, {$contractStatusEsiSelect}, {$contractLifecycleSelect},
              {$esiAcceptorIdSelect} AS esi_acceptor_id, {$esiAcceptorNameSelect} AS esi_acceptor_name,
              {$opsAssigneeIdSelect} AS ops_assignee_id, {$opsAssigneeNameSelect} AS ops_assignee_name, {$opsStatusSelect},
              {$validationSelect}, {$mismatchSelect},
              COALESCE(fs.system_name, r.from_name) AS from_name,
              COALESCE(ts.system_name, r.to_name) AS to_name,
              r.volume_m3, r.reward_isk, r.created_at, r.requester_display_name,
              a.hauler_user_id, u.display_name AS hauler_name
         FROM v_haul_request_display r
         LEFT JOIN eve_system fs ON fs.system_id = r.from_location_id AND r.from_location_type = 'system'
         LEFT JOIN eve_system ts ON ts.system_id = r.to_location_id AND r.to_location_type = 'system'
         {$requestKeyJoin}
         LEFT JOIN haul_assignment a ON a.request_id = r.request_id
         LEFT JOIN app_user u ON u.user_id = a.hauler_user_id
        WHERE r.corp_id = :cid{$contractLifecycleFilter}
        ORDER BY r.created_at DESC
        LIMIT 25",
      ['cid' => $corpId]
    );
  } elseif ($hasHaulRequest) {
    $hasRequestKey = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'request_key'");
    $requestKeySelect = $hasRequestKey ? 'r.request_key' : "'' AS request_key";
    $hasValidationJson = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_validation_json'");
    $validationSelect = $hasValidationJson ? 'r.contract_validation_json' : 'NULL AS contract_validation_json';
    $hasMismatchJson = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'mismatch_reason_json'");
    $mismatchSelect = $hasMismatchJson ? 'r.mismatch_reason_json' : 'NULL AS mismatch_reason_json';
    $hasEsiContractId = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'esi_contract_id'");
    $esiContractIdSelect = $hasEsiContractId ? 'r.esi_contract_id' : 'r.contract_id AS esi_contract_id';
    $hasEsiStatus = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'esi_status'");
    $esiStatusSelect = $hasEsiStatus ? 'r.esi_status' : 'r.contract_status_esi AS esi_status';
    $hasContractStatusEsi = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_status_esi'");
    $contractStatusEsiSelect = $hasContractStatusEsi ? 'r.contract_status_esi' : 'NULL AS contract_status_esi';
    $hasContractLifecycle = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_lifecycle'");
    $contractLifecycleSelect = $hasContractLifecycle ? 'r.contract_lifecycle' : 'NULL AS contract_lifecycle';
    $hasContractState = false;
    if (!$hasContractLifecycle) {
      $hasContractState = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_state'");
      if ($hasContractState) {
        $contractLifecycleSelect = 'r.contract_state AS contract_lifecycle';
      }
    }
    $contractLifecycleColumn = null;
    if ($hasContractLifecycle) {
      $contractLifecycleColumn = 'r.contract_lifecycle';
    } elseif (!empty($hasContractState)) {
      $contractLifecycleColumn = 'r.contract_state';
    }
    $contractLifecycleFilter = $contractLifecycleColumn
      ? " AND ({$contractLifecycleColumn} IS NULL OR {$contractLifecycleColumn} NOT IN ('FAILED','EXPIRED','DELIVERED'))"
      : " AND r.status NOT IN ('completed','delivered','cancelled','expired','rejected','failed')";
    $hasEsiAcceptorId = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'esi_acceptor_id'");
    $esiAcceptorIdSelect = $hasEsiAcceptorId ? 'r.esi_acceptor_id' : 'r.contract_acceptor_id AS esi_acceptor_id';
    $hasEsiAcceptorName = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'esi_acceptor_name'");
    $esiAcceptorNameSelect = $hasEsiAcceptorName ? 'r.esi_acceptor_name' : 'r.contract_acceptor_name AS esi_acceptor_name';
    $hasOpsAssigneeId = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'ops_assignee_id'");
    $opsAssigneeIdSelect = $hasOpsAssigneeId ? 'r.ops_assignee_id' : 'a.hauler_user_id AS ops_assignee_id';
    $hasOpsAssigneeName = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'ops_assignee_name'");
    $opsAssigneeNameSelect = $hasOpsAssigneeName ? 'r.ops_assignee_name' : 'h.display_name AS ops_assignee_name';
    $hasOpsStatus = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'ops_status'");
    $opsStatusSelect = $hasOpsStatus ? 'r.ops_status' : 'NULL AS ops_status';
    $requests = $db->select(
      "SELECT r.request_id, {$requestKeySelect}, r.status, {$esiContractIdSelect} AS esi_contract_id, r.contract_status,
              {$esiStatusSelect} AS esi_status, {$contractStatusEsiSelect}, {$contractLifecycleSelect},
              {$esiAcceptorIdSelect} AS esi_acceptor_id, {$esiAcceptorNameSelect} AS esi_acceptor_name,
              {$opsAssigneeIdSelect} AS ops_assignee_id, {$opsAssigneeNameSelect} AS ops_assignee_name, {$opsStatusSelect},
              {$validationSelect}, {$mismatchSelect},
              r.from_location_id, r.to_location_id, r.volume_m3, r.reward_isk, r.created_at,
              u.display_name AS requester_display_name, a.hauler_user_id,
              h.display_name AS hauler_name, fs.system_name AS from_name, ts.system_name AS to_name
         FROM haul_request r
         JOIN app_user u ON u.user_id = r.requester_user_id
         LEFT JOIN haul_assignment a ON a.request_id = r.request_id
         LEFT JOIN app_user h ON h.user_id = a.hauler_user_id
         LEFT JOIN eve_system fs ON fs.system_id = r.from_location_id AND r.from_location_type = 'system'
         LEFT JOIN eve_system ts ON ts.system_id = r.to_location_id AND r.to_location_type = 'system'
        WHERE r.corp_id = :cid{$contractLifecycleFilter}
        ORDER BY r.created_at DESC
        LIMIT 25",
      ['cid' => $corpId]
    );
  }

  if ($requests) {
    $requestsAvailable = true;
  }

  if ($canAssignOps) {
    $haulers = $db->select(
      "SELECT user_id, display_name, character_name
         FROM app_user
        WHERE corp_id = :cid
          AND status = 'active'
        ORDER BY display_name ASC",
      ['cid' => $corpId]
    );
  }
}

require __DIR__ . '/../../src/Views/operations.php';
