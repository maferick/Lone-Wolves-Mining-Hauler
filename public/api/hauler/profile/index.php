<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;

api_require_key();

if (empty($authCtx['user_id'])) {
  api_send_json(['ok' => false, 'error' => 'login_required'], 403);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$userId = (int)$authCtx['user_id'];

$ensureProfile = static function (Db $db, int $userId): void {
  $db->execute(
    "INSERT IGNORE INTO user_hauler_profile (user_id) VALUES (:uid)",
    ['uid' => $userId]
  );
};

if ($method === 'GET') {
  $ensureProfile($db, $userId);
  $row = $db->one(
    "SELECT user_id, can_fly_freighter, can_fly_jump_freighter, can_fly_dst, can_fly_br,
            preferred_service_class, max_cargo_m3_override
       FROM user_hauler_profile
      WHERE user_id = :uid
      LIMIT 1",
    ['uid' => $userId]
  );
  if (!$row) {
    api_send_json(['ok' => true, 'profile' => null]);
  }
  api_send_json([
    'ok' => true,
    'profile' => [
      'can_fly_freighter' => !empty($row['can_fly_freighter']),
      'can_fly_jump_freighter' => !empty($row['can_fly_jump_freighter']),
      'can_fly_dst' => !empty($row['can_fly_dst']),
      'can_fly_br' => !empty($row['can_fly_br']),
      'preferred_service_class' => $row['preferred_service_class'] !== null ? (string)$row['preferred_service_class'] : '',
      'max_cargo_m3_override' => $row['max_cargo_m3_override'] !== null ? (float)$row['max_cargo_m3_override'] : null,
    ],
  ]);
}

$payload = api_read_json();
$ensureProfile($db, $userId);

$allowedClasses = ['BR', 'DST', 'JF', 'FREIGHTER'];
$preferred = strtoupper(trim((string)($payload['preferred_service_class'] ?? '')));
$preferred = in_array($preferred, $allowedClasses, true) ? $preferred : '';

$maxOverrideRaw = $payload['max_cargo_m3_override'] ?? null;
$maxOverride = null;
if ($maxOverrideRaw !== null && $maxOverrideRaw !== '') {
  $maxOverrideValue = (float)$maxOverrideRaw;
  if ($maxOverrideValue > 0) {
    $maxOverride = $maxOverrideValue;
  }
}

$db->execute(
  "UPDATE user_hauler_profile
      SET can_fly_freighter = :freighter,
          can_fly_jump_freighter = :jump_freighter,
          can_fly_dst = :dst,
          can_fly_br = :br,
          preferred_service_class = :preferred,
          max_cargo_m3_override = :override,
          updated_at = UTC_TIMESTAMP()
    WHERE user_id = :uid",
  [
    'freighter' => !empty($payload['can_fly_freighter']) ? 1 : 0,
    'jump_freighter' => !empty($payload['can_fly_jump_freighter']) ? 1 : 0,
    'dst' => !empty($payload['can_fly_dst']) ? 1 : 0,
    'br' => !empty($payload['can_fly_br']) ? 1 : 0,
    'preferred' => $preferred !== '' ? $preferred : null,
    'override' => $maxOverride,
    'uid' => $userId,
  ]
);

api_send_json(['ok' => true]);
