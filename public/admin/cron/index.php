<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;
use App\Services\CronSyncService;
use App\Services\JobQueueService;

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

$cronService = new CronSyncService($db, $config, $services['esi'], $services['discord_webhook'] ?? null);
$cronStats = $cronService->getStats($corpId);
$runResult = null;
$runError = null;
$notice = null;

$syncInterval = (int)($_ENV['CRON_SYNC_INTERVAL'] ?? 300);
if ($syncInterval <= 0) {
  $syncInterval = 300;
}
$matchInterval = (int)($_ENV['CRON_MATCH_INTERVAL'] ?? 300);
if ($matchInterval <= 0) {
  $matchInterval = 300;
}

$loadTaskSettings = static function (Db $db, ?int $corpId): array {
  if ($corpId === null) {
    $rows = $db->select(
      "SELECT task_key, is_enabled
         FROM cron_task_setting
        WHERE corp_id IS NULL"
    );
  } else {
    $rows = $db->select(
      "SELECT task_key, is_enabled
         FROM cron_task_setting
        WHERE corp_id = :cid",
      ['cid' => $corpId]
    );
  }
  $settings = [];
  foreach ($rows as $row) {
    $settings[(string)$row['task_key']] = (int)$row['is_enabled'] === 1;
  }
  return $settings;
};

$saveTaskSetting = static function (Db $db, ?int $corpId, string $taskKey, bool $enabled): void {
  if ($corpId === null) {
    $db->execute(
      "INSERT INTO cron_task_setting (corp_id, task_key, is_enabled)
       VALUES (NULL, :task_key, :is_enabled)
       ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), updated_at = UTC_TIMESTAMP()",
      [
        'task_key' => $taskKey,
        'is_enabled' => $enabled ? 1 : 0,
      ]
    );
    return;
  }

  $db->execute(
    "INSERT INTO cron_task_setting (corp_id, task_key, is_enabled)
     VALUES (:cid, :task_key, :is_enabled)
     ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), updated_at = UTC_TIMESTAMP()",
    [
      'cid' => $corpId,
      'task_key' => $taskKey,
      'is_enabled' => $enabled ? 1 : 0,
    ]
  );
};

$isTaskEnabled = static function (array $settings, string $taskKey): bool {
  if (!array_key_exists($taskKey, $settings)) {
    return true;
  }
  return (bool)$settings[$taskKey];
};

$fetchLastJob = static function (Db $db, string $jobType, ?int $corpId): ?array {
  if ($corpId === null) {
    return $db->one(
      "SELECT *
         FROM job_queue
        WHERE job_type = :job_type
          AND corp_id IS NULL
        ORDER BY COALESCE(finished_at, started_at, run_at, created_at) DESC, job_id DESC
        LIMIT 1",
      ['job_type' => $jobType]
    );
  }
  return $db->one(
    "SELECT *
       FROM job_queue
      WHERE job_type = :job_type
        AND corp_id = :cid
      ORDER BY COALESCE(finished_at, started_at, run_at, created_at) DESC, job_id DESC
      LIMIT 1",
    [
      'job_type' => $jobType,
      'cid' => $corpId,
    ]
  );
};

$formatTimestamp = static function (?string $value): string {
  if (!$value) {
    return '—';
  }
  $ts = strtotime($value);
  if ($ts === false) {
    return '—';
  }
  return gmdate('Y-m-d H:i:s', $ts);
};

$formatDuration = static function (?string $start, ?string $end): string {
  if (!$start || !$end) {
    return '—';
  }
  $startTs = strtotime($start);
  $endTs = strtotime($end);
  if ($startTs === false || $endTs === false || $endTs < $startTs) {
    return '—';
  }
  $diff = $endTs - $startTs;
  if ($diff < 1) {
    return '0s';
  }
  $minutes = intdiv($diff, 60);
  $seconds = $diff % 60;
  if ($minutes > 0) {
    return sprintf('%dm %ds', $minutes, $seconds);
  }
  return sprintf('%ds', $seconds);
};

$extractJobMessage = static function (?array $job): string {
  if (!$job) {
    return '—';
  }
  if (!empty($job['last_error'])) {
    return (string)$job['last_error'];
  }
  if (empty($job['payload_json'])) {
    return '—';
  }
  $payload = Db::jsonDecode((string)$job['payload_json'], []);
  if (!is_array($payload)) {
    return '—';
  }
  $log = $payload['log'] ?? [];
  if (is_array($log) && $log !== []) {
    $last = end($log);
    if (is_array($last) && isset($last['message'])) {
      return (string)$last['message'];
    }
  }
  if (!empty($payload['progress']['label'])) {
    return (string)$payload['progress']['label'];
  }
  return '—';
};

$taskDefinitions = [
  JobQueueService::CRON_SYNC_JOB => [
    'key' => JobQueueService::CRON_SYNC_JOB,
    'name' => 'ESI Sync',
    'schedule' => $syncInterval . 's',
    'scope' => 'corp',
    'description' => 'Refreshes tokens, structures, public structures, and contracts.',
    'runner' => 'sync',
  ],
  JobQueueService::CONTRACT_MATCH_JOB => [
    'key' => JobQueueService::CONTRACT_MATCH_JOB,
    'name' => 'Contract Match',
    'schedule' => $matchInterval . 's',
    'scope' => 'corp',
    'description' => 'Matches open hauling requests against contracts.',
    'runner' => 'task',
  ],
  JobQueueService::WEBHOOK_DELIVERY_JOB => [
    'key' => JobQueueService::WEBHOOK_DELIVERY_JOB,
    'name' => 'Webhook Delivery',
    'schedule' => '60s',
    'scope' => 'global',
    'description' => 'Flushes queued Discord webhook deliveries.',
    'runner' => 'task',
  ],
];

$taskSettingsCorp = $loadTaskSettings($db, $corpId);
$taskSettingsGlobal = $loadTaskSettings($db, null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'toggle_task') {
    $taskKey = (string)($_POST['task_key'] ?? '');
    if (isset($taskDefinitions[$taskKey])) {
      $task = $taskDefinitions[$taskKey];
      $scopeCorpId = $task['scope'] === 'global' ? null : $corpId;
      $settings = $scopeCorpId === null ? $taskSettingsGlobal : $taskSettingsCorp;
      $enabled = !$isTaskEnabled($settings, $taskKey);
      $saveTaskSetting($db, $scopeCorpId, $taskKey, $enabled);
      $notice = $enabled
        ? "Enabled {$task['name']}."
        : "Disabled {$task['name']}.";
      $taskSettingsCorp = $loadTaskSettings($db, $corpId);
      $taskSettingsGlobal = $loadTaskSettings($db, null);
    }
  } else {
    $scope = (string)($_POST['scope'] ?? 'all');
    $useSde = !empty($_POST['sde']);
    if ($scope === 'sde') {
      $scope = 'universe';
      $useSde = true;
    }
    if (!in_array($scope, ['all', 'universe'], true)) {
      $scope = 'all';
    }
    if ($cronCharId <= 0 && $scope !== 'universe') {
      $runError = 'Set a cron character before running the sync.';
    } else {
      $force = isset($_POST['force']) && $_POST['force'] === '1';
      try {
        $runResult = $cronService->run($corpId, $cronCharId, [
          'force' => $force,
          'scope' => $scope,
          'sde' => $useSde,
        ]);
        $cronStats = $cronService->getStats($corpId);
      } catch (Throwable $e) {
        $runError = $e->getMessage();
      }
    }
  }
}

$cmd = 'php bin/cron.php';
$cmdUniverse = sprintf('php bin/cron_sync.php %d --scope=universe', $corpId);
$cmdUniverseSde = sprintf('php bin/cron_sync.php %d --scope=universe --sde', $corpId);

$recentJobs = $db->select(
  "SELECT job_id, corp_id, job_type, status, run_at, started_at, finished_at, created_at, payload_json, last_error
     FROM job_queue
    WHERE corp_id = :cid
       OR (corp_id IS NULL AND job_type = :webhook)
    ORDER BY created_at DESC
    LIMIT 20",
  [
    'cid' => $corpId,
    'webhook' => JobQueueService::WEBHOOK_DELIVERY_JOB,
  ]
);

$statusClassMap = [
  'succeeded' => 'status-success',
  'failed' => 'status-failed',
  'dead' => 'status-dead',
  'running' => 'status-running',
  'queued' => 'status-queued',
];

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="card">
  <div class="card-header">
    <h2>Cron Manager</h2>
    <p class="muted">Run the scheduler every minute. It queues the ESI sync, sends Discord webhooks, runs contract matching, and processes queued cron jobs.</p>
  </div>

  <div class="content">
    <?php if ($notice): ?>
      <div class="alert alert-success" style="margin-top:12px;"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($runError): ?>
      <div class="alert alert-warning" style="margin-top:12px;"><?= htmlspecialchars($runError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="cron-section">
      <ul class="muted" style="margin:0 0 12px; padding-left:18px;">
        <li>Use absolute paths and ensure log permissions.</li>
        <li>Use log rotation for /var/log/modularalliance/cron.log.</li>
        <li>Inspect failures in this dashboard and module logs.</li>
      </ul>

      <p class="muted"><strong>Corp ID:</strong> <?= (int)$corpId ?> · <strong>Configured character:</strong>
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

      <p class="muted" style="margin-top:12px;">Manual universe/map bootstrap (optional):</p>
      <div class="pill" style="margin:12px 0;">
        <code><?= htmlspecialchars($cmdUniverse, ENT_QUOTES, 'UTF-8') ?></code>
      </div>
      <div class="pill" style="margin:12px 0;">
        <code><?= htmlspecialchars($cmdUniverseSde, ENT_QUOTES, 'UTF-8') ?></code>
      </div>
    </div>

    <div class="cron-section">
      <h3>Scheduled tasks</h3>
      <table class="table cron-table">
        <thead>
          <tr>
            <th>Job Key</th>
            <th>Name</th>
            <th>Schedule</th>
            <th>Last Status</th>
            <th>Last Run</th>
            <th>Message</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($taskDefinitions as $taskKey => $task): ?>
            <?php
              $scopeCorpId = $task['scope'] === 'global' ? null : $corpId;
              $settings = $scopeCorpId === null ? $taskSettingsGlobal : $taskSettingsCorp;
              $enabled = $isTaskEnabled($settings, $taskKey);
              $lastJob = $fetchLastJob($db, $taskKey, $scopeCorpId);
              $lastStatus = $enabled ? ($lastJob['status'] ?? 'never') : 'disabled';
              $lastRun = $lastJob['finished_at'] ?? $lastJob['started_at'] ?? $lastJob['run_at'] ?? $lastJob['created_at'] ?? null;
              $statusClass = $statusClassMap[$lastStatus] ?? ($lastStatus === 'disabled' ? 'status-disabled' : 'status-never');
            ?>
            <tr>
              <td><code><?= htmlspecialchars($taskKey, ENT_QUOTES, 'UTF-8') ?></code></td>
              <td>
                <?= htmlspecialchars($task['name'], ENT_QUOTES, 'UTF-8') ?>
                <div class="cron-note"><?= htmlspecialchars($task['description'], ENT_QUOTES, 'UTF-8') ?></div>
              </td>
              <td><?= htmlspecialchars($task['schedule'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><span class="status-pill <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($lastStatus, ENT_QUOTES, 'UTF-8') ?></span></td>
              <td><?= htmlspecialchars($lastRun ? $formatTimestamp((string)$lastRun) : '—', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($extractJobMessage($lastJob), ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <div class="actions">
                  <?php if ($task['runner'] === 'sync'): ?>
                    <button class="btn" type="button" data-run-sync="all">Run Now</button>
                  <?php else: ?>
                    <button class="btn" type="button" data-run-task="<?= htmlspecialchars($taskKey, ENT_QUOTES, 'UTF-8') ?>">Run Now</button>
                  <?php endif; ?>
                  <form method="post">
                    <input type="hidden" name="action" value="toggle_task" />
                    <input type="hidden" name="task_key" value="<?= htmlspecialchars($taskKey, ENT_QUOTES, 'UTF-8') ?>" />
                    <button class="btn ghost" type="submit"><?= $enabled ? 'Disable' : 'Enable' ?></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div id="cron-task-message" class="cron-note"></div>
    </div>

    <div class="cron-section">
      <h3>Sync status</h3>
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

    <div class="cron-section">
      <h3>Recent jobs</h3>
      <table class="table cron-table">
        <thead>
          <tr>
            <th>Job</th>
            <th>Status</th>
            <th>Started</th>
            <th>Finished</th>
            <th>Duration</th>
            <th>Message</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($recentJobs === []): ?>
            <tr><td colspan="6" class="muted">No cron jobs yet.</td></tr>
          <?php else: ?>
            <?php foreach ($recentJobs as $job): ?>
              <?php
                $status = (string)($job['status'] ?? 'queued');
                $statusClass = $statusClassMap[$status] ?? 'status-never';
                $taskName = $taskDefinitions[$job['job_type']]['name'] ?? (string)$job['job_type'];
              ?>
              <tr>
                <td>
                  <div><?= htmlspecialchars($taskName, ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="cron-note"><code><?= htmlspecialchars((string)$job['job_type'], ENT_QUOTES, 'UTF-8') ?></code></div>
                </td>
                <td><span class="status-pill <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><?= htmlspecialchars($formatTimestamp($job['started_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($formatTimestamp($job['finished_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($formatDuration($job['started_at'] ?? null, $job['finished_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($extractJobMessage($job), ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

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
  const progressBar = document.getElementById('cron-progress-bar');
  const progressLabel = document.getElementById('cron-progress-label');
  const progressCount = document.getElementById('cron-progress-count');
  const logEl = document.getElementById('cron-log');
  const resultEl = document.getElementById('cron-result');
  const taskMessage = document.getElementById('cron-task-message');
  let pollTimer = null;
  let currentJobId = null;

  const setProgress = (progress = {}) => {
    if (!progressBar || !progressLabel || !progressCount) return;
    const current = Number(progress.current ?? 0);
    const total = Number(progress.total ?? 0);
    const pct = total > 0 ? Math.round((current / total) * 100) : 0;
    progressBar.style.width = `${pct}%`;
    progressLabel.textContent = progress.label ?? 'Idle';
    progressCount.textContent = `${current}/${total}`;
  };

  const renderLog = (log = []) => {
    if (!logEl) return;
    if (!Array.isArray(log) || log.length === 0) {
      logEl.textContent = 'No queued syncs yet.';
      return;
    }
    logEl.textContent = log.map(entry => `[${entry.time}] ${entry.message}`).join('\n');
  };

  const renderResult = (result) => {
    if (!resultEl) return;
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
        if (progressLabel) {
          progressLabel.textContent = data.error ?? 'Unable to load status.';
        }
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
      if (progressLabel) {
        progressLabel.textContent = 'Status check failed.';
      }
    }
  };

  const startJob = async (scope, force, useSde) => {
    stopPolling();
    setProgress({ current: 0, total: 0, label: 'Queueing...' });
    renderLog([{ time: new Date().toISOString(), message: 'Queueing cron sync...' }]);
    renderResult(null);
    try {
      const resp = await fetch(`${basePath}/api/cron/run`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ scope, force: force ? 1 : 0, sde: useSde ? 1 : 0 })
      });
      const data = await resp.json();
      if (!data.ok) {
        if (progressLabel) {
          progressLabel.textContent = data.error ?? 'Unable to queue sync.';
        }
        return;
      }
      currentJobId = data.job_id;
      await pollStatus();
      pollTimer = setInterval(pollStatus, 3500);
    } catch (err) {
      if (progressLabel) {
        progressLabel.textContent = 'Queue request failed.';
      }
    }
  };

  const runTask = async (taskKey) => {
    if (!taskMessage) return;
    taskMessage.textContent = 'Queueing task...';
    try {
      const resp = await fetch(`${basePath}/api/cron/tasks/run`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ task_key: taskKey })
      });
      const data = await resp.json();
      if (!data.ok) {
        taskMessage.textContent = data.error ?? 'Unable to queue task.';
        return;
      }
      taskMessage.textContent = `Queued ${taskKey} as job #${data.job_id}.`;
    } catch (err) {
      taskMessage.textContent = 'Queue request failed.';
    }
  };

  document.querySelectorAll('[data-run-sync]').forEach((button) => {
    button.addEventListener('click', () => {
      startJob('all', false, false);
    });
  });

  document.querySelectorAll('[data-run-task]').forEach((button) => {
    button.addEventListener('click', () => {
      const taskKey = button.getAttribute('data-run-task');
      if (taskKey) {
        runTask(taskKey);
      }
    });
  });
})();
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
