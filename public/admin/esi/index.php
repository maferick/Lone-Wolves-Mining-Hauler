<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'esi.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • ESI';

$msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'pull' && !empty($_POST['character_id'])) {
    $charId = (int)$_POST['character_id'];
    try {
      $result = $db->tx(fn(Db $db) => $services['esi']->contracts()->pull($corpId, $charId));
      $msg = "Pulled contracts: " . (int)($result['upserted_contracts'] ?? 0) . " (items: " . (int)($result['upserted_items'] ?? 0) . ")";
    } catch (Throwable $e) {
      $msg = "Pull failed: " . $e->getMessage();
    }
  }
}

$tokens = $db->select(
  "SELECT owner_id, owner_name, expires_at, scopes, token_status, last_error
     FROM sso_token
    WHERE corp_id=:cid AND owner_type='character'
    ORDER BY updated_at DESC",
  ['cid'=>$corpId]
);

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="card">
  <div class="card-header">
    <h2>ESI Tokens & Contract Sync</h2>
    <p class="muted">Tokens are refreshed automatically when near expiry. Pulls are cached via ETag.</p>
  </div>

  <div class="content">
    <?php if ($msg): ?><div class="pill"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <div style="margin-bottom:12px;">
      <a class="btn" href="<?= ($basePath ?: '') ?>/login">Add/Refresh Token (SSO)</a>
    </div>

    <table class="table">
      <thead>
        <tr>
          <th>Character</th>
          <th>Expires (UTC)</th>
          <th>Status</th>
          <th>Scopes</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tokens as $t): ?>
        <tr>
          <td><?= htmlspecialchars((string)($t['owner_name'] ?: $t['owner_id']), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)$t['expires_at'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)$t['token_status'], ENT_QUOTES, 'UTF-8') ?></td>
          <td style="max-width:420px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars((string)$t['scopes'], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars((string)$t['scopes'], ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td>
            <form method="post">
              <input type="hidden" name="character_id" value="<?= (int)$t['owner_id'] ?>" />
              <button class="btn" name="action" value="pull" type="submit">Pull Contracts</button>
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
