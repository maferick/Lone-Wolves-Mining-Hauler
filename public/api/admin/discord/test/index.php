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
$eventKey = trim((string)($data['event_key'] ?? ''));
$channelMapId = (int)($data['channel_map_id'] ?? 0);

if ($eventKey === '') {
  api_send_json(['ok' => false, 'error' => 'event_key required'], 400);
}

if (empty($services['discord_events'])) {
  api_send_json(['ok' => false, 'error' => 'Discord events service not configured.'], 500);
}

/** @var \App\Services\DiscordEventService $discordEvents */
$discordEvents = $services['discord_events'];
$queued = $discordEvents->enqueueTestMessage($corpId, $eventKey, $channelMapId ?: null);

api_send_json([
  'ok' => true,
  'queued' => $queued,
]);
