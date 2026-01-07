<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../bootstrap.php';

use App\Auth\Auth;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  api_send_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$userId = (int)($authCtx['user_id'] ?? 0);
if ($userId <= 0 || $db === null) {
  api_send_json(['ok' => false, 'error' => 'unauthorized'], 401);
}

$link = $db->one(
  "SELECT discord_user_id FROM discord_user_link WHERE user_id = :uid LIMIT 1",
  ['uid' => $userId]
);
if ($link && !empty($services['discord_events'])) {
  $services['discord_events']->enqueueRoleSyncUser((int)($authCtx['corp_id'] ?? 0), $userId, (string)$link['discord_user_id'], 'unlink');
}

$db->execute(
  "DELETE FROM discord_user_link WHERE user_id = :uid",
  ['uid' => $userId]
);

if ($link) {
  $discordUserId = trim((string)($link['discord_user_id'] ?? ''));
  $corpId = (int)($authCtx['corp_id'] ?? 0);
  if ($discordUserId !== '' && $corpId > 0) {
    $db->execute(
      "DELETE FROM discord_outbox
        WHERE corp_id = :cid
          AND event_key = 'discord.onboarding.dm'
          AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.discord_user_id')) = :did",
      [
        'cid' => $corpId,
        'did' => $discordUserId,
      ]
    );
  }
}

api_send_json(['ok' => true]);
