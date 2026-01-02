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

if (!Auth::can($authCtx, 'haul.execute')) {
  api_send_json(['ok' => false, 'error' => 'forbidden'], 403);
}

$payload = api_read_json();
$requestId = (int)($payload['request_id'] ?? 0);
$status = (string)($payload['status'] ?? '');
if ($requestId <= 0 || $status === '') {
  api_send_json(['ok' => false, 'error' => 'request_id and status required'], 400);
}

$allowedStatuses = [
  'in_progress' => 'pickup',
  'in_transit' => 'in_transit',
  'delivered' => 'delivered',
];

if (!array_key_exists($status, $allowedStatuses)) {
  api_send_json(['ok' => false, 'error' => 'invalid status'], 400);
}

$corpId = (int)($authCtx['corp_id'] ?? 0);
if ($corpId <= 0) {
  api_send_json(['ok' => false, 'error' => 'corp context missing'], 400);
}

$request = $db->one(
  "SELECT r.request_id,
          r.request_key,
          r.status,
          r.from_location_id,
          r.to_location_id,
          r.from_location_type,
          r.to_location_type,
          r.volume_m3,
          r.reward_isk,
          r.title,
          u.display_name AS requester_name,
          a.hauler_user_id,
          h.display_name AS hauler_name,
          h.character_id AS hauler_character_id,
          fs.system_name AS from_system_name,
          ts.system_name AS to_system_name
     FROM haul_request r
     LEFT JOIN app_user u ON u.user_id = r.requester_user_id
     LEFT JOIN haul_assignment a ON a.request_id = r.request_id
     LEFT JOIN app_user h ON h.user_id = a.hauler_user_id
     LEFT JOIN eve_system fs ON fs.system_id = r.from_location_id AND r.from_location_type = 'system'
     LEFT JOIN eve_system ts ON ts.system_id = r.to_location_id AND r.to_location_type = 'system'
    WHERE r.request_id = :rid AND r.corp_id = :cid
    LIMIT 1",
  ['rid' => $requestId, 'cid' => $corpId]
);

if (!$request) {
  api_send_json(['ok' => false, 'error' => 'request not found'], 404);
}

$eventType = $allowedStatuses[$status];

$db->tx(function ($db) use ($requestId, $corpId, $status, $eventType, $authCtx, $request): void {
  $deliveredAt = $status === 'delivered' ? 'UTC_TIMESTAMP()' : 'delivered_at';
  $acceptedAt = $status === 'in_progress' ? 'UTC_TIMESTAMP()' : 'accepted_at';

  $db->execute(
    "UPDATE haul_request
        SET status = :status,
            delivered_at = {$deliveredAt},
            accepted_at = {$acceptedAt},
            updated_at = UTC_TIMESTAMP()
      WHERE request_id = :rid AND corp_id = :cid",
    [
      'status' => $status,
      'rid' => $requestId,
      'cid' => $corpId,
    ]
  );

  $assignmentStatus = match ($status) {
    'in_transit' => 'in_transit',
    'delivered' => 'delivered',
    default => 'assigned',
  };

  $db->execute(
    "UPDATE haul_assignment
        SET status = :status,
            completed_at = IF(:status = 'delivered', UTC_TIMESTAMP(), completed_at),
            updated_at = UTC_TIMESTAMP()
      WHERE request_id = :rid",
    [
      'status' => $assignmentStatus,
      'rid' => $requestId,
    ]
  );

  $db->execute(
    "INSERT INTO haul_event
      (request_id, event_type, message, created_by_user_id, created_at)
     VALUES
      (:rid, :etype, :message, :uid, UTC_TIMESTAMP())",
    [
      'rid' => $requestId,
      'etype' => $eventType,
      'message' => 'Status updated to ' . $status,
      'uid' => (int)$authCtx['user_id'],
    ]
  );

  $db->audit(
    $corpId,
    (int)$authCtx['user_id'],
    (int)($authCtx['character_id'] ?? 0),
    'haul.status.update',
    'haul_request',
    (string)$requestId,
    $request,
    ['status' => $status],
    $_SERVER['REMOTE_ADDR'] ?? null,
    $_SERVER['HTTP_USER_AGENT'] ?? null
  );
});

if ($status === 'in_progress' && !empty($services['discord_webhook'])) {
  /** @var \App\Services\DiscordWebhookService $webhooks */
  $webhooks = $services['discord_webhook'];
  try {
    $actorName = (string)($authCtx['character_name'] ?? $authCtx['display_name'] ?? 'Unknown');
    $payload = $webhooks->buildHaulAssignmentPayload([
      'title' => 'Haul Picked Up #' . (string)$requestId,
      'request_id' => $requestId,
      'request_key' => (string)($request['request_key'] ?? ''),
      'from_system' => (string)($request['from_system_name'] ?? ''),
      'to_system' => (string)($request['to_system_name'] ?? ''),
      'volume_m3' => (float)($request['volume_m3'] ?? 0),
      'reward_isk' => (float)($request['reward_isk'] ?? 0),
      'requester' => (string)($request['requester_name'] ?? ''),
      'hauler' => (string)($request['hauler_name'] ?? ''),
      'hauler_character_id' => (int)($request['hauler_character_id'] ?? 0),
      'actor' => $actorName,
      'actor_label' => 'Picked Up By',
      'status' => 'in_progress',
    ]);
    $webhooks->enqueue($corpId, 'haul.assignment.picked_up', $payload);
  } catch (\Throwable $e) {
    // Ignore webhook enqueue failures to avoid blocking the status flow.
  }
}

api_send_json([
  'ok' => true,
  'request_id' => $requestId,
  'status' => $status,
]);
