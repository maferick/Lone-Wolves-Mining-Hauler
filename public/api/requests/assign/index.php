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
  "SELECT r.request_id,
          r.request_key,
          r.requester_user_id,
          r.status,
          r.from_location_id,
          r.to_location_id,
          r.from_location_type,
          r.to_location_type,
          r.volume_m3,
          r.reward_isk,
          r.title,
          r.ship_class,
          u.display_name AS requester_name,
          u.character_id AS requester_character_id,
          fs.system_name AS from_system_name,
          ts.system_name AS to_system_name
     FROM haul_request r
     LEFT JOIN app_user u ON u.user_id = r.requester_user_id
     LEFT JOIN eve_system fs ON fs.system_id = r.from_location_id AND r.from_location_type = 'system'
     LEFT JOIN eve_system ts ON ts.system_id = r.to_location_id AND r.to_location_type = 'system'
    WHERE r.request_id = :rid AND r.corp_id = :cid
    LIMIT 1",
  ['rid' => $requestId, 'cid' => $corpId]
);

if (!$request) {
  api_send_json(['ok' => false, 'error' => 'request not found'], 404);
}

$hauler = $db->one(
  "SELECT user_id, display_name, character_id, character_name FROM app_user WHERE user_id = :uid AND corp_id = :cid LIMIT 1",
  ['uid' => $haulerUserId, 'cid' => $corpId]
);

if (!$hauler) {
  api_send_json(['ok' => false, 'error' => 'hauler not found'], 404);
}

$db->tx(function ($db) use ($requestId, $haulerUserId, $corpId, $authCtx, $request, $hauler): void {
  $haulerName = (string)($hauler['display_name'] ?? '');
  $haulerCharacter = trim((string)($hauler['character_name'] ?? ''));
  $opsAssigneeName = $haulerCharacter !== '' ? $haulerName . ' (' . $haulerCharacter . ')' : $haulerName;

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
        SET ops_assignee_id = :ops_assignee_id,
            ops_assignee_name = :ops_assignee_name,
            updated_at = UTC_TIMESTAMP()
      WHERE request_id = :rid AND corp_id = :cid",
    [
      'rid' => $requestId,
      'cid' => $corpId,
      'ops_assignee_id' => $haulerUserId,
      'ops_assignee_name' => $opsAssigneeName,
    ]
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

if (!empty($services['discord_webhook'])) {
  /** @var \App\Services\DiscordWebhookService $webhooks */
  $webhooks = $services['discord_webhook'];
  try {
    $assignerName = (string)($authCtx['character_name'] ?? $authCtx['display_name'] ?? 'Unknown');
    $payload = $webhooks->buildHaulAssignmentPayload([
      'title' => 'Haul Assigned #' . (string)$requestId,
      'request_id' => $requestId,
      'request_key' => (string)($request['request_key'] ?? ''),
      'from_system' => (string)($request['from_system_name'] ?? ''),
      'to_system' => (string)($request['to_system_name'] ?? ''),
      'volume_m3' => (float)($request['volume_m3'] ?? 0),
      'reward_isk' => (float)($request['reward_isk'] ?? 0),
      'requester' => (string)($request['requester_name'] ?? ''),
      'requester_character_id' => (int)($request['requester_character_id'] ?? 0),
      'hauler' => (string)($hauler['display_name'] ?? ''),
      'hauler_character_id' => (int)($hauler['character_id'] ?? 0),
      'actor' => $assignerName,
      'actor_label' => 'Assigned By',
      'actor_character_id' => (int)($authCtx['character_id'] ?? 0),
      'status' => 'assigned',
      'ship_class' => (string)($request['ship_class'] ?? ''),
    ]);
    $webhooks->enqueue($corpId, 'haul.assignment.created', $payload);
  } catch (\Throwable $e) {
    // Ignore webhook enqueue failures to avoid blocking the assignment flow.
  }
}

api_send_json([
  'ok' => true,
  'request_id' => $requestId,
  'hauler_user_id' => $haulerUserId,
]);
