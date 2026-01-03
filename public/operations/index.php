<?php
declare(strict_types=1);

// Standalone operations endpoint (works even if routing rules are bypassed)
require_once __DIR__ . '/../../src/bootstrap.php';

$appName = $config['app']['name'];
$title = $appName . ' • Operations';
$queueStats = [
  'outstanding' => 0,
  'in_progress' => 0,
  'delivered' => 0,
];
$requests = [];
$haulers = [];
$requestsAvailable = false;
$dbOk = $health['db'] ?? false;
$apiKey = (string)($config['security']['api_key'] ?? '');

$canViewOps = !empty($authCtx['user_id']) && \App\Auth\Auth::can($authCtx, 'haul.request.read');
$canAssignOps = !empty($authCtx['user_id']) && \App\Auth\Auth::can($authCtx, 'haul.assign');
$corpId = (int)($authCtx['corp_id'] ?? ($config['corp']['id'] ?? 0));

if ($dbOk && $db !== null && $canViewOps && $corpId > 0) {
  $hasHaulRequest = (bool)$db->fetchValue("SHOW TABLES LIKE 'haul_request'");
  $hasRequestView = (bool)$db->fetchValue("SHOW FULL TABLES LIKE 'v_haul_request_display'");

  if ($hasHaulRequest) {
    $statsRow = $db->one(
      "SELECT
          SUM(CASE WHEN status IN ('requested','awaiting_contract','contract_linked','contract_mismatch','in_queue','draft','quoted','submitted','posted') THEN 1 ELSE 0 END) AS outstanding,
          SUM(CASE WHEN status IN ('in_progress','accepted','in_transit') THEN 1 ELSE 0 END) AS in_progress,
          SUM(CASE WHEN status IN ('completed','delivered') THEN 1 ELSE 0 END) AS delivered
        FROM haul_request
       WHERE corp_id = :cid",
      ['cid' => $corpId]
    );
    if ($statsRow) {
      $queueStats['outstanding'] = (int)($statsRow['outstanding'] ?? 0);
      $queueStats['in_progress'] = (int)($statsRow['in_progress'] ?? 0);
      $queueStats['delivered'] = (int)($statsRow['delivered'] ?? 0);
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
    $requests = $db->select(
      "SELECT r.request_id, {$requestKeySelect}, r.status, r.contract_id, r.contract_status, r.contract_status_esi,
              r.contract_state, r.contract_acceptor_name, {$validationSelect}, {$mismatchSelect},
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
        WHERE r.corp_id = :cid
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
    $requests = $db->select(
      "SELECT r.request_id, {$requestKeySelect}, r.status, r.contract_id, r.contract_status,
              r.contract_status_esi, r.contract_state, r.contract_acceptor_name, {$validationSelect}, {$mismatchSelect},
              r.from_location_id, r.to_location_id, r.volume_m3, r.reward_isk, r.created_at,
              u.display_name AS requester_display_name, a.hauler_user_id,
              h.display_name AS hauler_name, fs.system_name AS from_name, ts.system_name AS to_name
         FROM haul_request r
         JOIN app_user u ON u.user_id = r.requester_user_id
         LEFT JOIN haul_assignment a ON a.request_id = r.request_id
         LEFT JOIN app_user h ON h.user_id = a.hauler_user_id
         LEFT JOIN eve_system fs ON fs.system_id = r.from_location_id AND r.from_location_type = 'system'
         LEFT JOIN eve_system ts ON ts.system_id = r.to_location_id AND r.to_location_type = 'system'
        WHERE r.corp_id = :cid
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
