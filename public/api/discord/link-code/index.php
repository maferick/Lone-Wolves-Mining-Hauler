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

$limitWindowMinutes = 10;
$limitMax = 3;
$recentCount = (int)($db->fetchValue(
  "SELECT COUNT(*)
     FROM discord_link_code
    WHERE user_id = :uid
      AND created_at >= (UTC_TIMESTAMP() - INTERVAL {$limitWindowMinutes} MINUTE)",
  ['uid' => $userId]
) ?? 0);

if ($recentCount >= $limitMax) {
  api_send_json([
    'ok' => false,
    'error' => 'rate_limited',
    'message' => 'You have generated too many link codes recently. Please wait a few minutes and try again.',
  ], 429);
}

$generateCode = static function (): string {
  return strtoupper(bin2hex(random_bytes(4)));
};

$expiresAt = gmdate('Y-m-d H:i:s', time() + 600);
$code = null;
for ($attempt = 0; $attempt < 5; $attempt++) {
  $candidate = $generateCode();
  try {
    $db->execute(
      "INSERT INTO discord_link_code (code, user_id, expires_at)
       VALUES (:code, :uid, :expires_at)",
      [
        'code' => $candidate,
        'uid' => $userId,
        'expires_at' => $expiresAt,
      ]
    );
    $code = $candidate;
    break;
  } catch (PDOException $e) {
    $errorCode = $e->errorInfo[1] ?? null;
    if ((int)$errorCode === 1062) {
      continue;
    }
    throw $e;
  }
}

if ($code === null) {
  api_send_json(['ok' => false, 'error' => 'code_generation_failed'], 500);
}

api_send_json([
  'ok' => true,
  'code' => $code,
  'expires_at' => $expiresAt,
]);
