<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'webhook.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Webhooks';

$msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'create') {
    $name = trim((string)($_POST['name'] ?? 'Discord Webhook'));
    $url = trim((string)($_POST['url'] ?? ''));
    if ($url === '') $msg = "Webhook URL required.";
    else {
      $db->execute(
        "INSERT INTO discord_webhook (corp_id, webhook_name, webhook_url, is_enabled)
         VALUES (:cid, :n, :u, 1)",
        ['cid'=>$corpId,'n'=>$name,'u'=>$url]
      );
      $msg = "Created.";
    }
  } elseif ($action === 'toggle') {
    $id = (int)($_POST['webhook_id'] ?? 0);
    $db->execute("UPDATE discord_webhook SET is_enabled = 1 - is_enabled WHERE webhook_id=:id AND corp_id=:cid", ['id'=>$id,'cid'=>$corpId]);
    $msg = "Updated.";
  } elseif ($action === 'delete') {
    $id = (int)($_POST['webhook_id'] ?? 0);
    $db->execute("DELETE FROM discord_webhook WHERE webhook_id=:id AND corp_id=:cid", ['id'=>$id,'cid'=>$corpId]);
    $msg = "Deleted.";
  }
}

$hooks = $db->select("SELECT webhook_id, webhook_name, webhook_url, is_enabled FROM discord_webhook WHERE corp_id=:cid ORDER BY webhook_id DESC", ['cid'=>$corpId]);

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="card">
  <div class="card-header">
    <h2>Discord Webhooks</h2>
    <p class="muted">These are used for automated contract postings and operational notifications.</p>
  </div>

  <div class="content">
    <?php if ($msg): ?><div class="pill"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <form method="post" style="margin-bottom:12px;">
      <input type="hidden" name="action" value="create" />
      <div class="row">
        <div>
          <div class="label">Name</div>
          <input class="input" name="name" placeholder="Hauling Board" />
        </div>
        <div>
          <div class="label">Webhook URL</div>
          <input class="input" name="url" placeholder="https://discord.com/api/webhooks/..." />
        </div>
      </div>
      <div style="margin-top:12px;">
        <button class="btn" type="submit">Create</button>
      </div>
    </form>

    <table class="table">
      <thead>
        <tr>
          <th>Name</th>
          <th>URL</th>
          <th>Enabled</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($hooks as $h): ?>
        <tr>
          <td><?= htmlspecialchars((string)$h['webhook_name'], ENT_QUOTES, 'UTF-8') ?></td>
          <td style="max-width:520px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
            <?= htmlspecialchars((string)$h['webhook_url'], ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td><?= ((int)$h['is_enabled'] === 1) ? 'Yes' : 'No' ?></td>
          <td>
            <form method="post" style="display:flex; gap:8px;">
              <input type="hidden" name="webhook_id" value="<?= (int)$h['webhook_id'] ?>" />
              <button class="btn ghost" name="action" value="toggle" type="submit">Toggle</button>
              <button class="btn" name="action" value="delete" type="submit" onclick="return confirm('Delete webhook?')">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div style="margin-top:14px;">
      <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/">Back</a>
    </div>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
