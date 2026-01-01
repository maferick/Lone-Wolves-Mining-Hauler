<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'user.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Users';

$msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $userId = (int)($_POST['user_id'] ?? 0);
  $roleKey = (string)($_POST['role_key'] ?? '');
  $action = (string)($_POST['action'] ?? '');

  if ($userId > 0 && $roleKey !== '' && in_array($action, ['add','remove'], true)) {
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
    $msg = "Updated role assignments.";
  }
}

$users = $db->select(
  "SELECT u.user_id, u.display_name, u.character_id, u.character_name, u.last_login_at
     FROM app_user u
    WHERE u.corp_id = :cid
    ORDER BY u.last_login_at DESC, u.user_id DESC",
  ['cid'=>$corpId]
);

$roles = $db->select("SELECT role_key, role_name FROM role WHERE corp_id=:cid ORDER BY role_key", ['cid'=>$corpId]);

$userRolesRows = $db->select(
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

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="card">
  <div class="card-header">
    <h2>User & Role Management</h2>
    <p class="muted">Promote sub-admins, dispatchers, or haulers with controlled permissions.</p>
  </div>

  <div class="content">
    <?php if ($msg): ?><div class="pill"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <table class="table">
      <thead>
        <tr>
          <th>User</th>
          <th>Character</th>
          <th>Roles</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): $uid=(int)$u['user_id']; ?>
          <tr>
            <td><?= htmlspecialchars((string)$u['display_name'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($u['character_name'] ?: $u['character_id']), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars(implode(', ', $userRoles[$uid] ?? []), ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <form method="post" style="display:flex; gap:8px; align-items:center;">
                <input type="hidden" name="user_id" value="<?= $uid ?>" />
                <select name="role_key">
                  <?php foreach ($roles as $r): ?>
                    <option value="<?= htmlspecialchars((string)$r['role_key'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$r['role_name'], ENT_QUOTES, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn" name="action" value="add" type="submit">Add</button>
                <button class="btn ghost" name="action" value="remove" type="submit">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div style="margin-top:14px;">
      <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin">Back</a>
    </div>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
