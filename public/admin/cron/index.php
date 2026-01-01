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
  $scope = (string)($_POST['scope'] ?? 'all');
  if (!in_array($scope, ['all', 'universe'], true)) {
    $scope = 'all';
  }
  if ($cronCharId <= 0 && $scope !== 'universe') {
    $runError = 'Set a cron character before running the sync.';
  } else {
    $force = isset($_POST['force']) && $_POST['force'] === '1';
    try {
      $runResult = $cronService->run($corpId, $cronCharId, ['force' => $force, 'scope' => $scope]);
      $cronStats = $cronService->getStats($corpId);
    } catch (Throwable $e) {
      $runError = $e->getMessage();
    }
  }
}

$cmd = $cronCharId > 0
  ? sprintf('php bin/cron_sync.php %d %d', $corpId, $cronCharId)
  : sprintf('php bin/cron_sync.php %d <character_id>', $corpId);
$cmdUniverse = sprintf('php bin/cron_sync.php %d --scope=universe', $corpId);

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="card">
  <div class="card-header">
    <h2>Cron Manager</h2>
    <p class="muted">Use these commands in your scheduler to refresh tokens or pre-initialize universe data.</p>
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

    <p class="muted" style="margin-top:12px;">Universe/map bootstrap command (safe to run separately):</p>
    <div class="pill" style="margin:12px 0;">
      <code><?= htmlspecialchars($cmdUniverse, ENT_QUOTES, 'UTF-8') ?></code>
    </div>

    <p class="muted" style="margin-top:12px;">Example crontab entry (runs every minute):</p>
    <div class="pill" style="margin:12px 0;">
      <code>* * * * * <?= htmlspecialchars($cmd, ENT_QUOTES, 'UTF-8') ?></code>
    </div>

    <p class="muted" style="margin-top:12px;">Async worker (process queued web cron runs):</p>
    <div class="pill" style="margin:12px 0;">
      <code><?= htmlspecialchars('php bin/cron_job_worker.php', ENT_QUOTES, 'UTF-8') ?></code>
    </div>

    <p class="muted" style="margin-top:12px;">Example worker crontab entry (runs every minute):</p>
    <div class="pill" style="margin:12px 0;">
      <code>* * * * * <?= htmlspecialchars('php bin/cron_job_worker.php', ENT_QUOTES, 'UTF-8') ?></code>
    </div>

    <p class="muted">Tip: set the cron character on the <a href="<?= ($basePath ?: '') ?>/admin/esi/">ESI Tokens</a> page.</p>

    <h3 style="margin-top:18px;">Run now</h3>
    <form method="post" id="cron-run-form" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
      <button class="btn" type="submit" name="scope" value="all">Run sync now</button>
      <button class="btn ghost" type="submit" name="force" value="1">Run full sync (ignore cooldowns)</button>
      <button class="btn ghost" type="submit" name="scope" value="universe">Initialize universe & maps</button>
    </form>

    <div id="cron-async" style="margin-top:16px;">
      <div class="progress">
        <div class="progress-bar" id="cron-progress-bar" style="width:0%;"></div>
      </div>
      <div class="progress-meta">
        <span id="cron-progress-label" class="muted">Idle</span>
        <span class="pill subtle" id="cron-progress-count">0/0</span>
      </div>
      <pre class="cron-log" id="cron-log">No queued syncs yet.</pre>
      <pre class="cron-log" id="cron-result" style="display:none;"></pre>
    </div>

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
<script>
(() => {
  const basePath = <?= json_encode($basePath ?: '', JSON_UNESCAPED_SLASHES) ?>;
  const form = document.getElementById('cron-run-form');
  const progressBar = document.getElementById('cron-progress-bar');
  const progressLabel = document.getElementById('cron-progress-label');
  const progressCount = document.getElementById('cron-progress-count');
  const logEl = document.getElementById('cron-log');
  const resultEl = document.getElementById('cron-result');
  let pollTimer = null;
  let currentJobId = null;

  const setProgress = (progress = {}) => {
    const current = Number(progress.current ?? 0);
    const total = Number(progress.total ?? 0);
    const pct = total > 0 ? Math.round((current / total) * 100) : 0;
    progressBar.style.width = `${pct}%`;
    progressLabel.textContent = progress.label ?? 'Idle';
    progressCount.textContent = `${current}/${total}`;
  };

  const renderLog = (log = []) => {
    if (!Array.isArray(log) || log.length === 0) {
      logEl.textContent = 'No queued syncs yet.';
      return;
    }
    logEl.textContent = log.map(entry => `[${entry.time}] ${entry.message}`).join('\n');
  };

  const renderResult = (result) => {
    if (!result || Object.keys(result).length === 0) {
      resultEl.style.display = 'none';
      resultEl.textContent = '';
      return;
    }
    resultEl.style.display = 'block';
    resultEl.textContent = JSON.stringify(result, null, 2);
  };

  const stopPolling = () => {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  };

  const pollStatus = async () => {
    if (!currentJobId) return;
    try {
      const resp = await fetch(`${basePath}/api/cron/status?job_id=${currentJobId}`);
      const data = await resp.json();
      if (!data.ok) {
        progressLabel.textContent = data.error ?? 'Unable to load status.';
        return;
      }
      const payload = data.payload ?? {};
      setProgress(payload.progress ?? {});
      renderLog(payload.log ?? []);
      renderResult(payload.result ?? {});
      if (['succeeded', 'failed', 'dead'].includes(data.status)) {
        stopPolling();
      }
    } catch (err) {
      progressLabel.textContent = 'Status check failed.';
    }
  };

  const startJob = async (scope, force) => {
    stopPolling();
    setProgress({ current: 0, total: 0, label: 'Queueing...' });
    renderLog([{ time: new Date().toISOString(), message: 'Queueing cron sync...' }]);
    renderResult(null);
    try {
      const resp = await fetch(`${basePath}/api/cron/run`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ scope, force: force ? 1 : 0 })
      });
      const data = await resp.json();
      if (!data.ok) {
        progressLabel.textContent = data.error ?? 'Unable to queue sync.';
        return;
      }
      currentJobId = data.job_id;
      await pollStatus();
      pollTimer = setInterval(pollStatus, 3500);
    } catch (err) {
      progressLabel.textContent = 'Queue request failed.';
    }
  };

  form?.addEventListener('submit', (event) => {
    const submitter = event.submitter;
    if (!submitter) return;
    event.preventDefault();
    const scope = submitter.value === 'universe' ? 'universe' : 'all';
    const force = submitter.name === 'force';
    startJob(scope, force);
  });
})();
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
