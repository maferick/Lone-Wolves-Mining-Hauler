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

api_send_json(['ok' => true]);
