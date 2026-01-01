<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'user.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Rights';

$msg = null;
$msgTone = 'info';

$roles = $db->select(
  "SELECT role_id, role_key, role_name
     FROM role
    WHERE corp_id = :cid
    ORDER BY role_key",
  ['cid' => $corpId]
);

$roleById = [];
foreach ($roles as $role) {
  $roleById[(int)$role['role_id']] = $role;
}

$orderedPerms = [
  'haul.request.create',
  'haul.request.read',
  'haul.request.manage',
  'haul.assign',
  'haul.execute',
];
$orderPlaceholders = implode(',', array_fill(0, count($orderedPerms), '?'));

$permSql = "SELECT perm_id, perm_key, perm_name, description, created_at
              FROM permission
             ORDER BY
               CASE WHEN perm_key IN ($orderPlaceholders) THEN 0 ELSE 1 END,
               FIELD(perm_key, $orderPlaceholders),
               perm_key";
$permParams = array_merge($orderedPerms, $orderedPerms);
$perms = $db->select($permSql, $permParams);

$haulPermKeys = [
  'haul.request.create',
  'haul.request.read',
  'haul.request.manage',
  'haul.assign',
  'haul.execute',
];

$haulPerms = [];
$sitePerms = [];
foreach ($perms as $perm) {
  $permKey = (string)($perm['perm_key'] ?? '');
  if (in_array($permKey, $haulPermKeys, true)) {
    $haulPerms[] = $perm;
    continue;
  }
  $sitePerms[] = $perm;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $allow = $_POST['allow'] ?? [];

  try {
    $db->tx(function(Db $db) use ($roles, $roleById, $allow, $authCtx) {
      foreach ($roles as $role) {
        $roleId = (int)$role['role_id'];
        $roleKey = (string)$role['role_key'];

        if ($roleKey === 'admin') {
          continue;
        }

        $db->execute("DELETE FROM role_permission WHERE role_id = :rid", ['rid' => $roleId]);

        $allowedPerms = $allow[$roleId] ?? [];
        if (!is_array($allowedPerms)) {
          $allowedPerms = [];
        }

        foreach ($allowedPerms as $permId) {
          $permId = (int)$permId;
          if ($permId <= 0) continue;
          $db->execute(
            "INSERT INTO role_permission (role_id, perm_id, allow) VALUES (:rid, :pid, 1)",
            ['rid' => $roleId, 'pid' => $permId]
          );
        }
      }

      $adminRoleId = (int)$db->scalar("SELECT role_id FROM role WHERE role_key = 'admin' AND corp_id = :cid LIMIT 1", [
        'cid' => (int)($authCtx['corp_id'] ?? 0),
      ]);
      if ($adminRoleId > 0) {
        $db->execute(
          "INSERT IGNORE INTO role_permission (role_id, perm_id, allow)
           SELECT :rid, p.perm_id, 1 FROM permission p",
          ['rid' => $adminRoleId]
        );
      }
    });

    $msg = 'Updated rights assignments.';
  } catch (Throwable $e) {
    $msg = $e->getMessage();
    $msgTone = 'error';
  }
}

$rolePermRows = $db->select(
  "SELECT rp.role_id, rp.perm_id
     FROM role_permission rp
     JOIN role r ON r.role_id = rp.role_id
    WHERE r.corp_id = :cid AND rp.allow = 1",
  ['cid' => $corpId]
);

$rolePerms = [];
foreach ($rolePermRows as $row) {
  $rolePerms[(int)$row['role_id']][] = (int)$row['perm_id'];
}

ob_start();
?>
<section class="card">
  <div class="card-header">
    <h2>Rights & Permissions</h2>
    <p class="muted">Manage hauling operations separately from website administration. Admin always has full access.</p>
  </div>

  <div class="content">
    <?php if ($msg): ?><div class="pill <?= $msgTone === 'error' ? 'pill-danger' : '' ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <form method="post">
      <?php
      $renderTable = function(array $permRows, string $title, string $description) use ($roles, $rolePerms) {
        if (!$permRows) {
          return;
        }
        ?>
        <div style="margin-bottom:18px;">
          <div style="margin-bottom:10px;">
            <h3 style="margin-bottom:4px;"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h3>
            <p class="muted" style="margin:0;"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p>
          </div>
          <table class="table">
            <thead>
              <tr>
                <th>Permission</th>
                <th>Description</th>
                <?php foreach ($roles as $role): ?>
                  <th><?= htmlspecialchars((string)$role['role_name'], ENT_QUOTES, 'UTF-8') ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($permRows as $perm): ?>
                <tr>
                  <td>
                    <div><strong><?= htmlspecialchars((string)$perm['perm_name'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                    <div class="muted" style="font-size:12px;"><?= htmlspecialchars((string)$perm['perm_key'], ENT_QUOTES, 'UTF-8') ?></div>
                  </td>
                  <td><?= htmlspecialchars((string)($perm['description'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                  <?php foreach ($roles as $role): ?>
                    <?php
                      $roleId = (int)$role['role_id'];
                      $roleKey = (string)$role['role_key'];
                      $permId = (int)$perm['perm_id'];
                      $isAllowed = in_array($permId, $rolePerms[$roleId] ?? [], true);
                    ?>
                    <td style="text-align:center;">
                      <?php if ($roleKey === 'admin'): ?>
                        <span class="badge">All</span>
                      <?php else: ?>
                        <label>
                          <input type="checkbox" name="allow[<?= $roleId ?>][]" value="<?= $permId ?>" <?= $isAllowed ? 'checked' : '' ?> />
                        </label>
                      <?php endif; ?>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php
      };
      ?>

      <?php $renderTable($haulPerms, 'Hauling Operations', 'Manage haul request workflows, assignments, and execution states.'); ?>
      <?php $renderTable($sitePerms, 'Website Administration', 'Control configuration, users, integrations, and automation settings.'); ?>

      <div style="margin-top:14px; display:flex; gap:10px;">
        <button class="btn" type="submit">Save rights</button>
        <a class="btn ghost" href="<?= ($basePath ?: '') ?>/">Back</a>
      </div>
    </form>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../src/Views/layout.php';
