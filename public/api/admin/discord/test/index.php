<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../_helpers.php';
require_once __DIR__ . '/../../../bootstrap.php';

use App\Auth\Auth;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requireAdmin($authCtx);

$corpId = (int)($authCtx['corp_id'] ?? 0);
$data = api_read_json();
$action = trim((string)($data['action'] ?? ''));
$eventKey = trim((string)($data['event_key'] ?? ''));
$channelMapId = (int)($data['channel_map_id'] ?? 0);

if (empty($services['discord_events'])) {
  api_send_json(['ok' => false, 'error' => 'Discord events service not configured.'], 500);
}

/** @var \App\Services\DiscordEventService $discordEvents */
$discordEvents = $services['discord_events'];

if ($action === 'test_message') {
  $queued = $discordEvents->enqueueBotTestMessage($corpId);
  api_send_json(['ok' => true, 'queued' => $queued]);
}

if ($action === 'test_role_sync') {
  $userId = (int)($data['user_id'] ?? $authCtx['user_id'] ?? 0);
  $link = $db->one(
    "SELECT discord_user_id FROM discord_user_link WHERE user_id = :uid LIMIT 1",
    ['uid' => $userId]
  );
  if (!$link) {
    api_send_json(['ok' => false, 'error' => 'discord_link_missing'], 404);
  }
  $queued = $discordEvents->enqueueRoleSyncUser($corpId, $userId, (string)$link['discord_user_id']);
  api_send_json(['ok' => true, 'queued' => $queued]);
}

if ($eventKey === '') {
  api_send_json(['ok' => false, 'error' => 'event_key required'], 400);
}

$queued = $discordEvents->enqueueTestMessage($corpId, $eventKey, $channelMapId ?: null);

api_send_json([
  'ok' => true,
  'queued' => $queued,
]);
