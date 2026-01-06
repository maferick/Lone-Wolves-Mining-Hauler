<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../_helpers.php';
require_once __DIR__ . '/../../../bootstrap.php';

use App\Auth\Auth;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'webhook.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);
$data = api_read_json();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
  $rows = $db->select(
    "SELECT * FROM discord_channel_map WHERE corp_id = :cid ORDER BY event_key ASC, channel_map_id ASC",
    ['cid' => $corpId]
  );
  api_send_json(['ok' => true, 'channels' => $rows]);
}

$action = (string)($data['action'] ?? '');
if ($action === 'delete') {
  $mapId = (int)($data['channel_map_id'] ?? 0);
  if ($mapId <= 0) {
    api_send_json(['ok' => false, 'error' => 'channel_map_id required'], 400);
  }
  $db->execute(
    "DELETE FROM discord_channel_map WHERE channel_map_id = :id AND corp_id = :cid",
    ['id' => $mapId, 'cid' => $corpId]
  );
  api_send_json(['ok' => true]);
}

$mapId = (int)($data['channel_map_id'] ?? 0);
$eventKey = trim((string)($data['event_key'] ?? ''));
$mode = (string)($data['mode'] ?? 'webhook');
$channelId = trim((string)($data['channel_id'] ?? ''));
$webhookUrl = trim((string)($data['webhook_url'] ?? ''));
$isEnabled = !empty($data['is_enabled']) ? 1 : 0;

if ($eventKey === '') {
  api_send_json(['ok' => false, 'error' => 'event_key required'], 400);
}

if ($mapId > 0) {
  $db->execute(
    "UPDATE discord_channel_map
        SET event_key = :event_key,
            mode = :mode,
            channel_id = :channel_id,
            webhook_url = :webhook_url,
            is_enabled = :is_enabled,
            updated_at = UTC_TIMESTAMP()
      WHERE channel_map_id = :id AND corp_id = :cid",
    [
      'event_key' => $eventKey,
      'mode' => $mode,
      'channel_id' => $channelId !== '' ? $channelId : null,
      'webhook_url' => $webhookUrl !== '' ? $webhookUrl : null,
      'is_enabled' => $isEnabled,
      'id' => $mapId,
      'cid' => $corpId,
    ]
  );
  api_send_json(['ok' => true, 'channel_map_id' => $mapId]);
}

$mapId = $db->insert('discord_channel_map', [
  'corp_id' => $corpId,
  'event_key' => $eventKey,
  'mode' => $mode,
  'channel_id' => $channelId !== '' ? $channelId : null,
  'webhook_url' => $webhookUrl !== '' ? $webhookUrl : null,
  'is_enabled' => $isEnabled,
]);

api_send_json(['ok' => true, 'channel_map_id' => $mapId]);
