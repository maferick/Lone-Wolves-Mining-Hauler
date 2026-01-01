<?php
declare(strict_types=1);

namespace App\Auth;

use App\Db\Db;

/**
 * src/Auth/auth.php
 *
 * Placeholder auth module:
 * - Later: EVE SSO, RBAC checks (role_permission), session user loading
 * - For now: a minimal "guest" context to keep endpoints consistent
 */
final class Auth
{
  public static function context(): array
  {
    // TODO: replace with session-based identity
    return [
      'user_id' => null,
      'corp_id' => null,
      'character_id' => null,
      'roles' => [],
    ];
  }

  public static function requireRole(array $ctx, string $roleKey): void
  {
    if (!in_array($roleKey, $ctx['roles'] ?? [], true)) {
      http_response_code(403);
      echo "Forbidden";
      exit;
    }
  }
}
