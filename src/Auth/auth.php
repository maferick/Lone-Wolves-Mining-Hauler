<?php
declare(strict_types=1);

namespace App\Auth;

use App\Db\Db;

/**
 * src/Auth/auth.php
 *
 * Session-backed auth context:
 * - Expects `$_SESSION['user_id']` to be set after login (or by token exchange).
 * - User + corp identity loaded from `app_user`.
 * - Role keys loaded via `user_role` → `role` to drive RBAC checks.
 * - If token-based auth is added, populate the same session keys after verifying
 *   the token so downstream code can rely on a consistent session context.
 */
final class Auth
{
  public static function context(?Db $db = null): array
  {
    $uid = $_SESSION['user_id'] ?? null;
    $uid = $uid !== null ? (int)$uid : null;

    if ($db === null || !$uid) {
      return [
        'user_id' => null,
        'corp_id' => null,
        'character_id' => null,
        'display_name' => 'Guest',
        'roles' => [],
      ];
    }

    $u = $db->one(
      "SELECT user_id, corp_id, character_id, display_name, status
         FROM app_user WHERE user_id = :id LIMIT 1",
      ['id' => $uid]
    );

    if (!$u || ($u['status'] ?? '') !== 'active') {
      unset($_SESSION['user_id']);
      return [
        'user_id' => null,
        'corp_id' => null,
        'character_id' => null,
        'display_name' => 'Guest',
        'roles' => [],
      ];
    }

    $roles = $db->select(
      "SELECT r.role_key
         FROM user_role ur
         JOIN role r ON r.role_id = ur.role_id
        WHERE ur.user_id = :uid",
      ['uid' => $uid]
    );
    $roleKeys = array_values(array_map(fn($r) => (string)$r['role_key'], $roles));

    return [
      'user_id' => (int)$u['user_id'],
      'corp_id' => (int)$u['corp_id'],
      'character_id' => $u['character_id'] !== null ? (int)$u['character_id'] : null,
      'display_name' => $u['display_name'] ?? null,
      'roles' => $roleKeys,
    ];
  }

  public static function requireRole(array $ctx, string $roleKey): void
  {
    $roles = $ctx['roles'] ?? [];
    if (!in_array($roleKey, $roles, true) && !in_array('admin', $roles, true)) {
      http_response_code(403);
      echo "Forbidden";
      exit;
    }
  }
}
