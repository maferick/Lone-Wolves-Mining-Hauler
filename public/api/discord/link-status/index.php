<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../bootstrap.php';

use App\Auth\Auth;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);

$userId = (int)($authCtx['user_id'] ?? 0);

$link = null;
if ($userId > 0 && $db !== null) {
  $link = $db->one(
    "SELECT discord_user_id, discord_username, linked_at, last_seen_at
       FROM discord_user_link
      WHERE user_id = :uid
      LIMIT 1",
    ['uid' => $userId]
  );
}

if (!$link) {
  api_send_json(['ok' => true, 'linked' => false]);
}

api_send_json([
  'ok' => true,
  'linked' => true,
  'discord_user_id' => (string)($link['discord_user_id'] ?? ''),
  'discord_username' => $link['discord_username'] ?? null,
  'linked_at' => $link['linked_at'] ?? null,
  'last_seen_at' => $link['last_seen_at'] ?? null,
]);
