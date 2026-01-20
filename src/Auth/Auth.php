<?php
declare(strict_types=1);

namespace App\Auth;

use App\Db\Db;

/**
 * src/Auth/Auth.php
 *
 * Session-backed auth + RBAC.
 * - Stores user_id in session
 * - Loads roles + corp_id
 * - Helpers for permission enforcement
 */
final class Auth
{
  public static function initSession(array $config): void
  {
    // already started in bootstrap; here for future extensions
  }

  public static function login(int $userId): void
  {
    $_SESSION['user_id'] = $userId;
    $_SESSION['logged_in_at'] = time();
  }

  public static function logout(): void
  {
    unset($_SESSION['user_id'], $_SESSION['logged_in_at']);
  }

  public static function userId(): ?int
  {
    $v = $_SESSION['user_id'] ?? null;
    return $v !== null ? (int)$v : null;
  }

  public static function context(?Db $db): array
  {
    $uid = self::userId();
    if ($db === null) {
      // DB unavailable: keep the app up, but treat as guest.
      return [
        'user_id' => null,
        'corp_id' => null,
        'character_id' => null,
        'display_name' => 'Guest',
        'roles' => [],
        'perms' => [],
      ];
    }

    if (!$uid) {
      return [
        'user_id' => null,
        'corp_id' => null,
        'character_id' => null,
        'display_name' => 'Guest',
        'roles' => [],
        'perms' => [],
      ];
    }

    $u = $db->one(
      "SELECT user_id, corp_id, character_id, character_name, display_name, status, session_revoked_at
         FROM app_user WHERE user_id = :id LIMIT 1",
      ['id' => $uid]
    );

    if (!$u || ($u['status'] ?? '') !== 'active') {
      self::logout();
      return [
        'user_id' => null,
        'corp_id' => null,
        'character_id' => null,
        'display_name' => 'Guest',
        'roles' => [],
        'perms' => [],
      ];
    }

    $loggedInAt = $_SESSION['logged_in_at'] ?? null;
    if (!empty($u['session_revoked_at'])) {
      $revokedAt = strtotime((string)$u['session_revoked_at']);
      if ($revokedAt !== false) {
        if ($loggedInAt === null || (int)$loggedInAt < $revokedAt) {
          self::logout();
          return [
            'user_id' => null,
            'corp_id' => null,
            'character_id' => null,
            'display_name' => 'Guest',
            'roles' => [],
            'perms' => [],
          ];
        }
      }
    }

    $authz = new \App\Services\AuthzService($db);
    if (!$authz->isEntitledByUserRow($u)) {
      self::logout();
      return [
        'user_id' => null,
        'corp_id' => null,
        'character_id' => null,
        'display_name' => 'Guest',
        'roles' => [],
        'perms' => [],
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

    $perms = $db->select(
      "SELECT DISTINCT p.perm_key
         FROM user_role ur
         JOIN role_permission rp ON rp.role_id = ur.role_id AND rp.allow = 1
         JOIN permission p ON p.perm_id = rp.perm_id
        WHERE ur.user_id = :uid",
      ['uid' => $uid]
    );
    $permKeys = array_values(array_map(fn($p) => (string)$p['perm_key'], $perms));

    return [
      'user_id' => (int)$u['user_id'],
      'corp_id' => (int)$u['corp_id'],
      'character_id' => $u['character_id'] !== null ? (int)$u['character_id'] : null,
      'character_name' => $u['character_name'] ?? null,
      'display_name' => $u['display_name'] ?? null,
      'roles' => $roleKeys,
      'perms' => $permKeys,
    ];
  }

  public static function requireLogin(array $ctx): void
  {
    if (!$ctx['user_id']) {
      http_response_code(302);
      header('Location: ' . self::url('/login/'));
      exit;
    }
  }

  public static function can(array $ctx, string $permKey): bool
  {
    return in_array($permKey, $ctx['perms'] ?? [], true) || in_array('admin', $ctx['roles'] ?? [], true);
  }

  public static function hasRole(array $ctx, string $roleKey): bool
  {
    return in_array($roleKey, $ctx['roles'] ?? [], true);
  }

  public static function requirePerm(array $ctx, string $permKey): void
  {
    if (!self::can($ctx, $permKey)) {
      http_response_code(403);
      echo "Forbidden";
      exit;
    }
  }

  public static function url(string $path): string
  {
    $basePath = rtrim((string)($_ENV['APP_BASE_PATH'] ?? ''), '/');
    if ($basePath === '') {
      // fallback: use config-derived base_path if available globally
      $basePath = rtrim((string)($GLOBALS['config']['app']['base_path'] ?? ''), '/');
    }
    return ($basePath ?: '') . $path;
  }
}
