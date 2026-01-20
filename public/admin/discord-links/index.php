<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requireAdmin($authCtx);

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

$recentCodes = $db->select(
  "SELECT c.code, c.created_at, c.expires_at, c.used_at, c.used_by_discord_user_id,
          u.display_name, u.character_name,
          TIMESTAMPDIFF(SECOND, c.created_at, c.expires_at) AS ttl_seconds,
          TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), c.expires_at) AS ttl_remaining_seconds
     FROM discord_link_code c
     JOIN app_user u ON u.user_id = c.user_id
    WHERE u.corp_id = :cid
    ORDER BY c.created_at DESC
    LIMIT 20",
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
<section class="card" style="margin-top:20px;">
  <div class="card-header">
    <h2>Recent Link Codes (Diagnostics)</h2>
    <p class="muted">Latest 20 link codes with TTL details (UTC).</p>
  </div>
  <div class="content">
    <?php if (!$recentCodes): ?>
      <p class="muted">No recent link codes found.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Code</th>
            <th>User</th>
            <th>Created (UTC)</th>
            <th>Expires (UTC)</th>
            <th>Used At (UTC)</th>
            <th>Used By</th>
            <th>TTL (sec)</th>
            <th>TTL Remaining (sec)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentCodes as $codeRow): ?>
            <?php
              $userLabel = trim((string)($codeRow['display_name'] ?? '') ?: (string)($codeRow['character_name'] ?? 'User'));
              if (!empty($codeRow['character_name']) && $userLabel !== (string)$codeRow['character_name']) {
                $userLabel .= ' (' . (string)$codeRow['character_name'] . ')';
              }
            ?>
            <tr>
              <td><?= htmlspecialchars((string)($codeRow['code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($userLabel, ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)($codeRow['created_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)($codeRow['expires_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)($codeRow['used_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)($codeRow['used_by_discord_user_id'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)($codeRow['ttl_seconds'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)($codeRow['ttl_remaining_seconds'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
