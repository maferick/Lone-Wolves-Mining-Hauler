<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

use App\Auth\Auth;

api_require_key();

$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);
if (empty($authCtx['user_id'])) {
  api_send_json(['ok' => false, 'error' => 'login required'], 403);
}

if (!Auth::can($authCtx, 'haul.assign')) {
  api_send_json(['ok' => false, 'error' => 'forbidden'], 403);
}

$payload = api_read_json();
$requestId = (int)($payload['request_id'] ?? 0);
$haulerUserId = (int)($payload['hauler_user_id'] ?? 0);
if ($requestId <= 0) {
  api_send_json(['ok' => false, 'error' => 'request_id required'], 400);
}

if ($haulerUserId <= 0) {
  $haulerUserId = (int)$authCtx['user_id'];
}

$corpId = (int)($authCtx['corp_id'] ?? 0);
if ($corpId <= 0) {
  api_send_json(['ok' => false, 'error' => 'corp context missing'], 400);
}

$request = $db->one(
  "SELECT request_id, requester_user_id, status
     FROM haul_request
    WHERE request_id = :rid AND corp_id = :cid
    LIMIT 1",
  ['rid' => $requestId, 'cid' => $corpId]
);

if (!$request) {
  api_send_json(['ok' => false, 'error' => 'request not found'], 404);
}

$hauler = $db->one(
  "SELECT user_id, display_name FROM app_user WHERE user_id = :uid AND corp_id = :cid LIMIT 1",
  ['uid' => $haulerUserId, 'cid' => $corpId]
);

if (!$hauler) {
  api_send_json(['ok' => false, 'error' => 'hauler not found'], 404);
}

$db->tx(function ($db) use ($requestId, $haulerUserId, $corpId, $authCtx, $request): void {
  $db->execute(
    "INSERT INTO haul_assignment
      (request_id, hauler_user_id, assigned_by_user_id, status, started_at, created_at, updated_at)
     VALUES
      (:rid, :hid, :aid, 'assigned', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE
      hauler_user_id=VALUES(hauler_user_id),
      assigned_by_user_id=VALUES(assigned_by_user_id),
      status='assigned',
      started_at=IFNULL(started_at, UTC_TIMESTAMP()),
      updated_at=UTC_TIMESTAMP()",
    [
      'rid' => $requestId,
      'hid' => $haulerUserId,
      'aid' => (int)$authCtx['user_id'],
    ]
  );

  $db->execute(
    "UPDATE haul_request
        SET status = CASE
          WHEN status IN ('requested','awaiting_contract','in_queue','draft','quoted','submitted','posted','accepted') THEN 'in_progress'
          ELSE status
        END,
        updated_at = UTC_TIMESTAMP()
      WHERE request_id = :rid AND corp_id = :cid",
    ['rid' => $requestId, 'cid' => $corpId]
  );

  $db->execute(
    "INSERT INTO haul_event
      (request_id, event_type, message, created_by_user_id, created_at)
     VALUES
      (:rid, 'assigned', :message, :uid, UTC_TIMESTAMP())",
    [
      'rid' => $requestId,
      'message' => 'Assigned to user ' . (string)$haulerUserId,
      'uid' => (int)$authCtx['user_id'],
    ]
  );

  $db->audit(
    $corpId,
    (int)$authCtx['user_id'],
    (int)($authCtx['character_id'] ?? 0),
    'haul.assign',
    'haul_assignment',
    (string)$requestId,
    $request,
    ['hauler_user_id' => $haulerUserId],
    $_SERVER['REMOTE_ADDR'] ?? null,
    $_SERVER['HTTP_USER_AGENT'] ?? null
  );
});

api_send_json([
  'ok' => true,
  'request_id' => $requestId,
  'hauler_user_id' => $haulerUserId,
]);
