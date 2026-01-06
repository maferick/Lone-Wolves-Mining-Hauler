<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'webhook.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Discord Links';

$msg = null;
$msgTone = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  $userId = (int)($_POST['user_id'] ?? 0);

  if ($action === 'unlink' && $userId > 0) {
    $db->execute(
      "DELETE l
         FROM discord_user_link l
         JOIN app_user u ON u.user_id = l.user_id
        WHERE l.user_id = :uid
          AND u.corp_id = :cid",
      ['uid' => $userId, 'cid' => $corpId]
    );
    $msg = 'Discord link removed.';
  }
}

$links = $db->select(
  "SELECT l.user_id, l.discord_user_id, l.discord_username, l.linked_at, l.last_seen_at,
          u.display_name, u.character_name, u.character_id
     FROM discord_user_link l
     JOIN app_user u ON u.user_id = l.user_id
    WHERE u.corp_id = :cid
    ORDER BY l.linked_at DESC",
  ['cid' => $corpId]
);

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="card">
  <div class="card-header">
    <h2>Discord Account Links</h2>
    <p class="muted">Review linked Discord users and unlink when needed.</p>
  </div>
  <div class="content">
    <?php if ($msg): ?><div class="pill <?= $msgTone === 'error' ? 'pill-danger' : '' ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if (!$links): ?>
      <p class="muted">No Discord links found yet.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Portal User</th>
            <th>Discord User</th>
            <th>Linked</th>
            <th>Last Seen</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($links as $link): ?>
            <?php
              $userLabel = trim((string)($link['display_name'] ?? '') ?: (string)($link['character_name'] ?? 'User'));
              if (!empty($link['character_name']) && $userLabel !== (string)$link['character_name']) {
                $userLabel .= ' (' . (string)$link['character_name'] . ')';
              }
              $discordLabel = (string)($link['discord_username'] ?? '');
              if ($discordLabel === '') {
                $discordLabel = (string)($link['discord_user_id'] ?? 'Unknown');
              } else {
                $discordLabel .= ' • ' . (string)($link['discord_user_id'] ?? '');
              }
            ?>
            <tr>
              <td><?= htmlspecialchars($userLabel, ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($discordLabel, ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)($link['linked_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)($link['last_seen_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="action" value="unlink" />
                  <input type="hidden" name="user_id" value="<?= (int)$link['user_id'] ?>" />
                  <button class="btn ghost" type="submit">Unlink</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <div class="card-footer">
    <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/">Back</a>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
