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
$title = $appName . ' • Cron';

$cronSetting = $db->one(
  "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'esi.cron' LIMIT 1",
  ['cid' => $corpId]
);
$cronJson = $cronSetting ? Db::jsonDecode($cronSetting['setting_json'], []) : [];
$cronCharId = (int)($cronJson['character_id'] ?? 0);
$cronCharName = (string)($cronJson['character_name'] ?? '');

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

    <div style="margin-top:14px;">
      <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/">Back</a>
    </div>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
