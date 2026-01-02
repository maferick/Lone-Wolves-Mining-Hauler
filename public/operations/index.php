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
$requestsAvailable = false;
$dbOk = $health['db'] ?? false;

$canViewOps = !empty($authCtx['user_id']) && \App\Auth\Auth::can($authCtx, 'haul.request.read');
$corpId = (int)($authCtx['corp_id'] ?? ($config['corp']['id'] ?? 0));

if ($dbOk && $db !== null && $canViewOps && $corpId > 0) {
  $hasHaulRequest = (bool)$db->fetchValue("SHOW TABLES LIKE 'haul_request'");
  $hasRequestView = (bool)$db->fetchValue("SHOW FULL TABLES LIKE 'v_haul_request_display'");

  if ($hasHaulRequest) {
    $statsRow = $db->one(
      "SELECT
          SUM(CASE WHEN status IN ('requested','awaiting_contract','in_queue','draft','quoted','submitted','posted') THEN 1 ELSE 0 END) AS outstanding,
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
    $requests = $db->select(
      "SELECT r.request_id, r.status, r.from_name, r.to_name, r.volume_m3, r.reward_isk,
              r.created_at, r.requester_display_name, a.hauler_user_id, u.display_name AS hauler_name
         FROM v_haul_request_display r
         LEFT JOIN haul_assignment a ON a.request_id = r.request_id
         LEFT JOIN app_user u ON u.user_id = a.hauler_user_id
        WHERE r.corp_id = :cid
        ORDER BY r.created_at DESC
        LIMIT 25",
      ['cid' => $corpId]
    );
  } elseif ($hasHaulRequest) {
    $requests = $db->select(
      "SELECT r.request_id, r.status, r.from_location_id, r.to_location_id, r.volume_m3, r.reward_isk,
              r.created_at, u.display_name AS requester_display_name, a.hauler_user_id,
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
}

require __DIR__ . '/../../src/Views/operations.php';
