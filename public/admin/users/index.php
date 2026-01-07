<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
$isDispatcher = Auth::hasRole($authCtx, 'dispatcher');
$isAdmin = Auth::hasRole($authCtx, 'admin');
$canManageUsers = Auth::can($authCtx, 'user.manage');
$dispatcherLimited = $isDispatcher && !$isAdmin;
if (!$canManageUsers && !$isDispatcher) {
  http_response_code(403);
  echo "Forbidden";
  exit;
}
$allowedDispatcherRoles = ['requester', 'hauler'];

$corpId = (int)($authCtx['corp_id'] ?? 0);
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Users';

$msg = null;
$msgTone = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $userId = (int)($_POST['user_id'] ?? 0);
  $roleKey = (string)($_POST['role_key'] ?? '');
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($userId > 0 && $roleKey !== '' && in_array($action, ['add','remove'], true)) {
      if ($dispatcherLimited && !in_array($roleKey, $allowedDispatcherRoles, true)) {
        http_response_code(403);
        echo "Forbidden";
        exit;
      }
      $db->tx(function(Db $db) use ($corpId, $userId, $roleKey, $action, $authCtx) {
        $roleId = (int)$db->scalar("SELECT role_id FROM role WHERE corp_id=:cid AND role_key=:rk", ['cid'=>$corpId,'rk'=>$roleKey]);
        if ($roleId <= 0) throw new RuntimeException("Role not found.");

        if ($action === 'add') {
          $db->execute("INSERT IGNORE INTO user_role (user_id, role_id) VALUES (:u, :r)", ['u'=>$userId,'r'=>$roleId]);
        } else {
          $db->execute("DELETE FROM user_role WHERE user_id=:u AND role_id=:r", ['u'=>$userId,'r'=>$roleId]);
        }

        $db->audit($corpId, $authCtx['user_id'], $authCtx['character_id'], 'user.role.' . $action, 'user_role', "{$userId}:{$roleId}", null, ['role_key'=>$roleKey], $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
      });
      if (!empty($services['discord_events'])) {
        $link = $db->one(
          "SELECT discord_user_id FROM discord_user_link WHERE user_id = :uid LIMIT 1",
          ['uid' => $userId]
        );
        if ($link) {
          $services['discord_events']->enqueueRoleSyncUser($corpId, $userId, (string)$link['discord_user_id']);
        }
      }
      $msg = "Updated role assignments.";
    } elseif ($userId > 0 && $action === 'delete') {
      if ($dispatcherLimited) {
        http_response_code(403);
        echo "Forbidden";
        exit;
      }
      $db->tx(function(Db $db) use ($corpId, $userId, $authCtx) {
        if ($userId === (int)($authCtx['user_id'] ?? 0)) {
          throw new RuntimeException("You cannot delete your own account.");
        }

        $isAdmin = (int)$db->scalar(
          "SELECT COUNT(*)
             FROM user_role ur
             JOIN role r ON r.role_id = ur.role_id
            WHERE ur.user_id = :uid AND r.corp_id = :cid AND r.role_key = 'admin'",
          ['uid' => $userId, 'cid' => $corpId]
        );
        if ($isAdmin > 0) {
          $adminCount = (int)$db->scalar(
            "SELECT COUNT(DISTINCT ur.user_id)
               FROM user_role ur
               JOIN role r ON r.role_id = ur.role_id
              WHERE r.corp_id = :cid AND r.role_key = 'admin'",
            ['cid' => $corpId]
          );
          if ($adminCount <= 1) {
            throw new RuntimeException("Cannot delete the last admin user.");
          }
        }

        $db->execute("UPDATE audit_log SET actor_user_id = NULL WHERE actor_user_id = :uid", ['uid' => $userId]);
        $db->execute("UPDATE app_setting SET updated_by_user_id = NULL WHERE updated_by_user_id = :uid", ['uid' => $userId]);
        $db->execute("DELETE FROM app_user WHERE user_id = :uid AND corp_id = :cid", ['uid' => $userId, 'cid' => $corpId]);

        $db->audit($corpId, $authCtx['user_id'], $authCtx['character_id'], 'user.delete', 'app_user', (string)$userId, null, ['user_id'=>$userId], $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
      });
      $msg = "Deleted user.";
    }
  } catch (RuntimeException $e) {
    $msg = $e->getMessage();
    $msgTone = 'error';
  }
}

$users = $db->select(
  "SELECT u.user_id, u.display_name, u.character_id, u.character_name, u.last_login_at
     FROM app_user u
    WHERE u.corp_id = :cid
    ORDER BY u.last_login_at DESC, u.user_id DESC",
  ['cid'=>$corpId]
);

$roles = $dispatcherLimited
  ? $db->select(
    "SELECT role_key, role_name
       FROM role
      WHERE corp_id=:cid AND role_key IN ('requester','hauler')
      ORDER BY role_key",
    ['cid'=>$corpId]
  )
  : $db->select("SELECT role_key, role_name FROM role WHERE corp_id=:cid ORDER BY role_key", ['cid'=>$corpId]);

$userRolesRows = $dispatcherLimited
  ? $db->select(
    "SELECT ur.user_id, r.role_key
       FROM user_role ur
       JOIN role r ON r.role_id = ur.role_id
      WHERE r.corp_id = :cid AND r.role_key IN ('requester','hauler')",
    ['cid'=>$corpId]
  )
  : $db->select(
    "SELECT ur.user_id, r.role_key
       FROM user_role ur
       JOIN role r ON r.role_id = ur.role_id
      WHERE r.corp_id = :cid",
    ['cid'=>$corpId]
  );
$userRoles = [];
foreach ($userRolesRows as $r) {
  $userRoles[(int)$r['user_id']][] = (string)$r['role_key'];
}
$roleNameByKey = [];
foreach ($roles as $role) {
  $roleNameByKey[(string)$role['role_key']] = (string)$role['role_name'];
}

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="card">
  <div class="card-header">
    <h2>User & Role Management</h2>
    <p class="muted">Assign Requester or Hauler access with controlled permissions.</p>
  </div>

  <div class="content">
    <?php if ($msg): ?><div class="pill <?= $msgTone === 'error' ? 'pill-danger' : '' ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <div class="form-grid" style="margin-bottom:14px;">
      <div class="form-field">
        <label class="form-label" for="user-search">Search users</label>
        <input class="input" id="user-search" type="search" placeholder="Search by user, character, or role…" />
      </div>
    </div>

    <table class="table">
      <thead>
        <tr>
          <th>User</th>
          <th>Character</th>
          <th>Rights</th>
          <?php if (!$dispatcherLimited): ?>
            <th>Actions</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): $uid=(int)$u['user_id']; ?>
          <?php $roleKeys = $userRoles[$uid] ?? []; ?>
          <?php $roleNames = array_map(fn($key) => $roleNameByKey[$key] ?? $key, $roleKeys); ?>
          <tr>
            <td><?= htmlspecialchars((string)$u['display_name'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($u['character_name'] ?: $u['character_id']), ENT_QUOTES, 'UTF-8') ?></td>
            <td data-roles="<?= htmlspecialchars(implode(' ', $roleNames), ENT_QUOTES, 'UTF-8') ?>">
              <div class="actions">
                <?php foreach ($roles as $r): ?>
                  <?php
                    $roleKey = (string)$r['role_key'];
                    $hasRole = in_array($roleKey, $roleKeys, true);
                  ?>
                  <form method="post" class="role-toggle" style="display:flex; align-items:center; gap:6px;">
                    <input type="hidden" name="user_id" value="<?= $uid ?>" />
                    <input type="hidden" name="role_key" value="<?= htmlspecialchars($roleKey, ENT_QUOTES, 'UTF-8') ?>" />
                    <input type="hidden" name="action" value="<?= $hasRole ? 'remove' : 'add' ?>" />
                    <label style="display:flex; align-items:center; gap:6px;">
                      <input class="role-checkbox" type="checkbox" <?= $hasRole ? 'checked' : '' ?> />
                      <span><?= htmlspecialchars((string)$r['role_name'], ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                  </form>
                <?php endforeach; ?>
              </div>
            </td>
            <?php if (!$dispatcherLimited): ?>
              <td>
                <form method="post">
                  <input type="hidden" name="user_id" value="<?= $uid ?>" />
                  <button class="btn danger" name="action" value="delete" type="submit" onclick="return confirm('Delete this user?')">Delete</button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div style="margin-top:14px;">
      <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/">Back</a>
    </div>
  </div>
</section>
<script src="<?= ($basePath ?: '') ?>/assets/js/admin/users.js" defer></script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
