<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;
use App\Services\CronSyncService;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'esi.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Cron';

$cronSetting = $db->one(
  "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'esi.cron' LIMIT 1",
  ['cid' => $corpId]
);
$cronJson = $cronSetting ? Db::jsonDecode($cronSetting['setting_json'], []) : [];
$cronCharId = (int)($cronJson['character_id'] ?? 0);
$cronCharName = (string)($cronJson['character_name'] ?? '');

$cronService = new CronSyncService($db, $config, $services['esi']);
$cronStats = $cronService->getStats($corpId);
$runResult = null;
$runError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if ($cronCharId <= 0) {
    $runError = 'Set a cron character before running the sync.';
  } else {
    $force = isset($_POST['force']) && $_POST['force'] === '1';
    try {
      $runResult = $cronService->run($corpId, $cronCharId, ['force' => $force]);
      $cronStats = $cronService->getStats($corpId);
    } catch (Throwable $e) {
      $runError = $e->getMessage();
    }
  }
}

$cmd = $cronCharId > 0
  ? sprintf('php bin/cron_sync.php %d %d', $corpId, $cronCharId)
  : sprintf('php bin/cron_sync.php %d <character_id>', $corpId);

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="card">
  <div class="card-header">
    <h2>Cron Manager</h2>
    <p class="muted">Use this command in your scheduler to refresh tokens and sync ESI data.</p>
  </div>

  <div class="content">
    <p><strong>Corp ID:</strong> <?= (int)$corpId ?></p>
    <p><strong>Configured character:</strong>
      <?= $cronCharId > 0
        ? htmlspecialchars(($cronCharName !== '' ? $cronCharName : (string)$cronCharId), ENT_QUOTES, 'UTF-8')
        : 'Not set' ?>
    </p>

    <div class="pill" style="margin:12px 0;">
      <code><?= htmlspecialchars($cmd, ENT_QUOTES, 'UTF-8') ?></code>
    </div>

    <p class="muted" style="margin-top:12px;">Example crontab entry (runs every minute):</p>
    <div class="pill" style="margin:12px 0;">
      <code>* * * * * <?= htmlspecialchars($cmd, ENT_QUOTES, 'UTF-8') ?></code>
    </div>

    <p class="muted">Tip: set the cron character on the <a href="<?= ($basePath ?: '') ?>/admin/esi/">ESI Tokens</a> page.</p>

    <h3 style="margin-top:18px;">Run now</h3>
    <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
      <button class="btn" type="submit" name="force" value="0">Run sync now</button>
      <button class="btn ghost" type="submit" name="force" value="1">Run full sync (ignore cooldowns)</button>
    </form>

    <?php if ($runError): ?>
      <div class="notice error" style="margin-top:12px;"><?= htmlspecialchars($runError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($runResult): ?>
      <div class="pill" style="margin-top:12px; white-space:pre-wrap;">
        <code><?= htmlspecialchars(json_encode($runResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?></code>
      </div>
    <?php endif; ?>

    <h3 style="margin-top:18px;">Last sync timestamps</h3>
    <ul class="muted" style="margin-top:8px;">
      <?php if ($cronStats === []): ?>
        <li>No syncs recorded yet.</li>
      <?php else: ?>
        <?php foreach ($cronStats as $key => $value): ?>
          <li><strong><?= htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      <?php endif; ?>
    </ul>

    <div style="margin-top:14px;">
      <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/">Back</a>
    </div>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
