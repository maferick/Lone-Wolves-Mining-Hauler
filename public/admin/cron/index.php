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

$normalizeInterval = static function (int $value, int $fallback): int {
  $interval = $value > 0 ? $value : $fallback;
  return max(60, $interval);
};
$syncInterval = $normalizeInterval((int)($_ENV['CRON_SYNC_INTERVAL'] ?? 300), 300);
$tokenRefreshInterval = $normalizeInterval((int)($_ENV['CRON_TOKEN_REFRESH_INTERVAL'] ?? 300), 300);
$structuresInterval = $normalizeInterval((int)($_ENV['CRON_STRUCTURES_INTERVAL'] ?? 900), 900);
$publicStructuresInterval = $normalizeInterval((int)($_ENV['CRON_PUBLIC_STRUCTURES_INTERVAL'] ?? 86400), 86400);
$contractsInterval = $normalizeInterval((int)($_ENV['CRON_CONTRACTS_INTERVAL'] ?? 300), 300);
$alliancesInterval = $normalizeInterval((int)($_ENV['CRON_ALLIANCES_INTERVAL'] ?? 86400), 86400);
$npcStructuresInterval = $normalizeInterval((int)($_ENV['CRON_NPC_STRUCTURES_INTERVAL'] ?? 86400), 86400);
$matchInterval = $normalizeInterval((int)($_ENV['CRON_MATCH_INTERVAL'] ?? 300), 300);
$webhookInterval = 60;
$discordInterval = 60;
$webhookRequeueInterval = $normalizeInterval((int)($_ENV['CRON_WEBHOOK_REQUEUE_INTERVAL'] ?? 900), 900);
$intervalSettings = [];
$intervalSettingRow = $db->one(
  "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'cron.intervals' LIMIT 1",
  ['cid' => $corpId]
);
if ($intervalSettingRow && !empty($intervalSettingRow['setting_json'])) {
  $intervalSettings = Db::jsonDecode((string)$intervalSettingRow['setting_json'], []);
  if (!is_array($intervalSettings)) {
    $intervalSettings = [];
  }
}

$intervalSettingsGlobal = [];
$intervalSettingGlobalRow = $db->one(
  "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'cron.intervals' LIMIT 1"
);
if ($intervalSettingGlobalRow && !empty($intervalSettingGlobalRow['setting_json'])) {
  $intervalSettingsGlobal = Db::jsonDecode((string)$intervalSettingGlobalRow['setting_json'], []);
  if (!is_array($intervalSettingsGlobal)) {
    $intervalSettingsGlobal = [];
  }
}

$formatInterval = static function (int $seconds): string {
  if ($seconds % 60 === 0) {
    $minutes = (int)($seconds / 60);
    return sprintf('%ds (%dm)', $seconds, $minutes);
  }
  return sprintf('%ds', $seconds);
};

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
    'interval' => $normalizeInterval((int)($intervalSettings[JobQueueService::CRON_SYNC_JOB] ?? 0), $syncInterval),
    'scope' => 'corp',
    'description' => 'Refreshes universe data and the stargate graph.',
    'sync_scope' => 'universe',
    'runner' => 'sync',
  ],
  JobQueueService::CRON_TOKEN_REFRESH_JOB => [
    'key' => JobQueueService::CRON_TOKEN_REFRESH_JOB,
    'name' => 'Token Refresh',
    'interval' => $normalizeInterval((int)($intervalSettings[JobQueueService::CRON_TOKEN_REFRESH_JOB] ?? 0), $tokenRefreshInterval),
    'scope' => 'corp',
    'description' => 'Refreshes corp ESI tokens.',
    'runner' => 'task',
  ],
  JobQueueService::CRON_STRUCTURES_JOB => [
    'key' => JobQueueService::CRON_STRUCTURES_JOB,
    'name' => 'Structures',
    'interval' => $normalizeInterval((int)($intervalSettings[JobQueueService::CRON_STRUCTURES_JOB] ?? 0), $structuresInterval),
    'scope' => 'corp',
    'description' => 'Pulls corp structure data.',
    'runner' => 'task',
  ],
  JobQueueService::CRON_PUBLIC_STRUCTURES_JOB => [
    'key' => JobQueueService::CRON_PUBLIC_STRUCTURES_JOB,
    'name' => 'Public Structures',
    'interval' => $normalizeInterval((int)($intervalSettings[JobQueueService::CRON_PUBLIC_STRUCTURES_JOB] ?? 0), $publicStructuresInterval),
    'scope' => 'corp',
    'description' => 'Pulls public structure data.',
    'runner' => 'task',
  ],
  JobQueueService::CRON_CONTRACTS_JOB => [
    'key' => JobQueueService::CRON_CONTRACTS_JOB,
    'name' => 'Contracts',
    'interval' => $normalizeInterval((int)($intervalSettings[JobQueueService::CRON_CONTRACTS_JOB] ?? 0), $contractsInterval),
    'scope' => 'corp',
    'description' => 'Pulls corp contracts.',
    'runner' => 'task',
  ],
  JobQueueService::CRON_ALLIANCES_JOB => [
    'key' => JobQueueService::CRON_ALLIANCES_JOB,
    'name' => 'Alliance Cache',
    'interval' => $normalizeInterval((int)($intervalSettingsGlobal[JobQueueService::CRON_ALLIANCES_JOB] ?? 0), $alliancesInterval),
    'scope' => 'global',
    'description' => 'Prefills alliance name cache for the allowlist search.',
    'runner' => 'task',
  ],
  JobQueueService::CRON_NPC_STRUCTURES_JOB => [
    'key' => JobQueueService::CRON_NPC_STRUCTURES_JOB,
    'name' => 'NPC Structures Cache',
    'interval' => $normalizeInterval((int)($intervalSettingsGlobal[JobQueueService::CRON_NPC_STRUCTURES_JOB] ?? 0), $npcStructuresInterval),
    'scope' => 'global',
    'description' => 'Prefills NPC structure data via ESI station resolution for location search.',
    'runner' => 'task',
  ],
  JobQueueService::CONTRACT_MATCH_JOB => [
    'key' => JobQueueService::CONTRACT_MATCH_JOB,
    'name' => 'Contract Match',
    'interval' => $normalizeInterval((int)($intervalSettings[JobQueueService::CONTRACT_MATCH_JOB] ?? 0), $matchInterval),
    'scope' => 'corp',
    'description' => 'Matches open hauling requests against contracts.',
    'runner' => 'task',
  ],
  JobQueueService::WEBHOOK_DELIVERY_JOB => [
    'key' => JobQueueService::WEBHOOK_DELIVERY_JOB,
    'name' => 'Webhook Delivery',
    'interval' => $normalizeInterval((int)($intervalSettingsGlobal[JobQueueService::WEBHOOK_DELIVERY_JOB] ?? 0), $webhookInterval),
    'scope' => 'global',
    'description' => 'Flushes queued Discord webhook deliveries.',
    'runner' => 'task',
  ],
  JobQueueService::DISCORD_DELIVERY_JOB => [
    'key' => JobQueueService::DISCORD_DELIVERY_JOB,
    'name' => 'Discord Delivery',
    'interval' => $normalizeInterval((int)($intervalSettingsGlobal[JobQueueService::DISCORD_DELIVERY_JOB] ?? 0), $discordInterval),
    'scope' => 'global',
    'description' => 'Flushes queued Discord outbox notifications.',
    'runner' => 'task',
  ],
  JobQueueService::WEBHOOK_REQUEUE_JOB => [
    'key' => JobQueueService::WEBHOOK_REQUEUE_JOB,
    'name' => 'Webhook Requeue',
    'interval' => $normalizeInterval((int)($intervalSettingsGlobal[JobQueueService::WEBHOOK_REQUEUE_JOB] ?? 0), $webhookRequeueInterval),
    'scope' => 'global',
    'description' => 'Requeues failed Discord webhooks that are still enabled.',
    'runner' => 'task',
  ],
];

$taskSettingsCorp = $loadTaskSettings($db, $corpId);
$taskSettingsGlobal = $loadTaskSettings($db, null);
$globalTaskKeys = [];
foreach ($taskDefinitions as $taskKey => $taskDefinition) {
  if (($taskDefinition['scope'] ?? '') === 'global') {
    $globalTaskKeys[] = $taskKey;
  }
}
$recentJobScopeParams = ['cid' => $corpId];
$recentJobGlobalClause = '';
if ($globalTaskKeys !== []) {
  $placeholders = [];
  foreach ($globalTaskKeys as $index => $taskKey) {
    $param = 'global_' . $index;
    $placeholders[] = ':' . $param;
    $recentJobScopeParams[$param] = $taskKey;
  }
  $recentJobGlobalClause = ' OR (corp_id IS NULL AND job_type IN (' . implode(', ', $placeholders) . '))';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'clear_recent_jobs') {
    $db->execute(
      "DELETE FROM job_queue
        WHERE corp_id = :cid{$recentJobGlobalClause}",
      $recentJobScopeParams
    );
    $notice = 'Cleared recent job history.';
  } elseif ($action === 'toggle_task') {
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
  } elseif ($action === 'update_interval') {
    $taskKey = (string)($_POST['task_key'] ?? '');
    if (isset($taskDefinitions[$taskKey])) {
      $task = $taskDefinitions[$taskKey];
      $interval = (int)($_POST['interval_seconds'] ?? 0);
      $interval = max(60, $interval);
      if ($task['scope'] === 'global') {
        $intervalSettingsGlobal[$taskKey] = $interval;
        $db->execute(
          "INSERT INTO app_setting (corp_id, setting_key, setting_json)
           VALUES (0, 'cron.intervals', :json)
           ON DUPLICATE KEY UPDATE setting_json = VALUES(setting_json), updated_at = UTC_TIMESTAMP()",
          [
            'json' => Db::jsonEncode($intervalSettingsGlobal),
          ]
        );
      } else {
        $intervalSettings[$taskKey] = $interval;
        $db->execute(
          "INSERT INTO app_setting (corp_id, setting_key, setting_json)
           VALUES (:cid, 'cron.intervals', :json)
           ON DUPLICATE KEY UPDATE setting_json = VALUES(setting_json), updated_at = UTC_TIMESTAMP()",
          [
            'cid' => $corpId,
            'json' => Db::jsonEncode($intervalSettings),
          ]
        );
      }
      $notice = "Updated {$task['name']} interval to {$interval}s.";
      $taskDefinitions[$taskKey]['interval'] = $interval;
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

$recentJobPage = max(1, (int)($_GET['page'] ?? 1));
$recentJobPerPage = 10;
$recentJobMax = 999;

$recentJobTotalRow = $db->one(
  "SELECT COUNT(*) AS total
     FROM job_queue
    WHERE corp_id = :cid{$recentJobGlobalClause}",
  $recentJobScopeParams
);
$recentJobTotal = (int)($recentJobTotalRow['total'] ?? 0);
if ($recentJobTotal > $recentJobMax) {
  $recentJobTotal = $recentJobMax;
}
$recentJobTotalPages = max(1, (int)ceil($recentJobTotal / $recentJobPerPage));
if ($recentJobPage > $recentJobTotalPages) {
  $recentJobPage = $recentJobTotalPages;
}
$recentJobOffset = ($recentJobPage - 1) * $recentJobPerPage;

$recentJobParams = $recentJobScopeParams + [
  'scope_limit' => $recentJobMax,
  'limit' => $recentJobPerPage,
  'offset' => $recentJobOffset,
];
$recentJobs = $db->select(
  "SELECT job_id, corp_id, job_type, status, run_at, started_at, finished_at, created_at, payload_json, last_error
     FROM (
       SELECT job_id, corp_id, job_type, status, run_at, started_at, finished_at, created_at, payload_json, last_error
         FROM job_queue
        WHERE corp_id = :cid{$recentJobGlobalClause}
        ORDER BY created_at DESC, job_id DESC
        LIMIT :scope_limit
     ) AS recent_jobs
    ORDER BY created_at DESC, job_id DESC
    LIMIT :limit OFFSET :offset",
  $recentJobParams
);

$statusClassMap = [
  'succeeded' => 'status-success',
  'failed' => 'status-failed',
  'dead' => 'status-dead',
  'running' => 'status-running',
  'queued' => 'status-queued',
];
$taskStates = [];
$taskAlerts = [];
$now = time();

foreach ($taskDefinitions as $taskKey => $task) {
  $scopeCorpId = $task['scope'] === 'global' ? null : $corpId;
  $settings = $scopeCorpId === null ? $taskSettingsGlobal : $taskSettingsCorp;
  $enabled = $isTaskEnabled($settings, $taskKey);
  $lastJob = $fetchLastJob($db, $taskKey, $scopeCorpId);
  $lastStatus = $enabled ? ($lastJob['status'] ?? 'never') : 'disabled';
  $lastRun = $lastJob['finished_at'] ?? $lastJob['started_at'] ?? $lastJob['run_at'] ?? $lastJob['created_at'] ?? null;
  $statusClass = $statusClassMap[$lastStatus] ?? ($lastStatus === 'disabled' ? 'status-disabled' : 'status-never');
  $interval = (int)$task['interval'];
  $stale = false;
  if ($enabled && $interval > 0) {
    if ($lastRun) {
      $lastRunTs = strtotime((string)$lastRun);
      if ($lastRunTs !== false && ($now - $lastRunTs) > ($interval * 5)) {
        $stale = true;
      }
    } else {
      $stale = true;
    }
  }
  if ($stale) {
    $taskAlerts[] = sprintf('%s has not run within the last %d cycles.', $task['name'], 5);
  }

  $taskStates[$taskKey] = [
    'task' => $task,
    'enabled' => $enabled,
    'last_job' => $lastJob,
    'last_status' => $lastStatus,
    'last_run' => $lastRun,
    'status_class' => $statusClass,
  ];
}

ob_start();
?>
<section class="card" data-base-path="<?= htmlspecialchars($basePath ?: '', ENT_QUOTES, 'UTF-8') ?>">
  <div class="card-header">
    <h2>Cron Manager</h2>
    <p class="muted">Run the scheduler every minute. It queues ESI sync, token refresh, structure pulls, contract pulls, Discord webhooks, and contract matching.</p>
  </div>

  <div class="content">
    <?php if ($notice): ?>
      <div class="alert alert-success" style="margin-top:12px;"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($runError): ?>
      <div class="alert alert-warning" style="margin-top:12px;"><?= htmlspecialchars($runError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($taskAlerts !== []): ?>
      <div class="alert alert-warning" style="margin-top:12px;">
        <strong>Attention needed:</strong>
        <ul style="margin:8px 0 0; padding-left:18px;">
          <?php foreach ($taskAlerts as $alert): ?>
            <li><?= htmlspecialchars($alert, ENT_QUOTES, 'UTF-8') ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="cron-section">
      <p class="muted" style="margin:0 0 12px;">
        Run the scheduler every minute. Task intervals can be adjusted below (minimum 60 seconds). Universe + SDE bootstraps run via the sync command.
      </p>

      <p class="muted"><strong>Corp ID:</strong> <?= (int)$corpId ?> · <strong>Configured character:</strong>
        <?= $cronCharId > 0
          ? htmlspecialchars(($cronCharName !== '' ? $cronCharName : (string)$cronCharId), ENT_QUOTES, 'UTF-8')
          : 'Not set' ?>
      </p>

      <div class="pill" style="margin:12px 0;">
        <code><?= htmlspecialchars($cmd, ENT_QUOTES, 'UTF-8') ?></code>
      </div>

      <p class="muted" style="margin-top:12px;">Example crontab entry:</p>
      <div class="pill" style="margin:12px 0;">
        <code>* * * * * <?= htmlspecialchars($cmd, ENT_QUOTES, 'UTF-8') ?></code>
      </div>

      <p class="muted">Tip: set the cron character on the <a href="<?= ($basePath ?: '') ?>/admin/esi/">ESI Tokens</a> page.</p>

      <details style="margin-top:12px;">
        <summary class="muted">Manual universe/map bootstrap (optional)</summary>
        <div class="pill" style="margin:12px 0;">
          <code><?= htmlspecialchars($cmdUniverse, ENT_QUOTES, 'UTF-8') ?></code>
        </div>
        <div class="pill" style="margin:12px 0;">
          <code><?= htmlspecialchars($cmdUniverseSde, ENT_QUOTES, 'UTF-8') ?></code>
        </div>
      </details>
    </div>

    <div class="cron-section">
      <h3>Scheduled tasks</h3>
      <table class="table cron-table">
        <thead>
          <tr>
            <th>Job Key</th>
            <th>Name</th>
            <th>Interval</th>
            <th>Last Status</th>
            <th>Last Run</th>
            <th>Message</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($taskStates as $taskKey => $state): ?>
            <?php
              $task = $state['task'];
              $enabled = $state['enabled'];
              $lastJob = $state['last_job'];
              $lastStatus = $state['last_status'];
              $lastRun = $state['last_run'];
              $statusClass = $state['status_class'];
              $interval = (int)$task['interval'];
            ?>
            <tr>
              <td><code><?= htmlspecialchars($taskKey, ENT_QUOTES, 'UTF-8') ?></code></td>
              <td>
                <?= htmlspecialchars($task['name'], ENT_QUOTES, 'UTF-8') ?>
                <div class="cron-note"><?= htmlspecialchars($task['description'], ENT_QUOTES, 'UTF-8') ?></div>
              </td>
              <td>
                <form method="post" class="cron-interval-form">
                  <input type="hidden" name="action" value="update_interval" />
                  <input type="hidden" name="task_key" value="<?= htmlspecialchars($taskKey, ENT_QUOTES, 'UTF-8') ?>" />
                  <label class="visually-hidden" for="interval-<?= htmlspecialchars($taskKey, ENT_QUOTES, 'UTF-8') ?>">Interval seconds</label>
                  <input
                    id="interval-<?= htmlspecialchars($taskKey, ENT_QUOTES, 'UTF-8') ?>"
                    type="number"
                    name="interval_seconds"
                    min="60"
                    step="60"
                    value="<?= (int)$interval ?>"
                  />
                  <button class="btn ghost" type="submit">Update</button>
                </form>
                <div class="cron-note">
                  Minimum 60s.
                  <?php if ($task['scope'] === 'global'): ?>
                    Global override stored in settings (falls back to environment).
                  <?php endif; ?>
                </div>
              </td>
              <td><span class="status-pill <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($lastStatus, ENT_QUOTES, 'UTF-8') ?></span></td>
              <td><?= htmlspecialchars($lastRun ? $formatTimestamp((string)$lastRun) : '—', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($extractJobMessage($lastJob), ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <div class="actions">
                  <?php if ($task['runner'] === 'sync'): ?>
                    <button class="btn" type="button" data-run-sync="<?= htmlspecialchars((string)($task['sync_scope'] ?? 'all'), ENT_QUOTES, 'UTF-8') ?>">Run Now</button>
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
      <div class="cron-pagination" id="cron-log-pagination" style="display:none;">
        <button class="btn ghost" type="button" id="cron-log-prev">Previous</button>
        <span class="muted" id="cron-log-page">Page 1 of 1</span>
        <button class="btn ghost" type="button" id="cron-log-next">Next</button>
      </div>
      <pre class="cron-log" id="cron-result" style="display:none;"></pre>
    </div>

    <div class="cron-section">
      <h3>Recent jobs</h3>
      <div class="row" style="align-items:center; justify-content:space-between; margin-bottom:8px;">
        <div class="muted">Showing up to the latest <?= htmlspecialchars((string)$recentJobMax, ENT_QUOTES, 'UTF-8') ?> entries.</div>
        <form method="post">
          <input type="hidden" name="action" value="clear_recent_jobs" />
          <button class="btn ghost" type="submit">Clear recent jobs</button>
        </form>
      </div>
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
      <?php if ($recentJobTotalPages > 1): ?>
        <div class="cron-pagination">
          <?php
            $pageParams = $_GET;
            $pageParams['page'] = max(1, $recentJobPage - 1);
          ?>
          <a class="btn ghost <?= $recentJobPage <= 1 ? 'disabled' : '' ?>" href="<?= ($basePath ?: '') ?>/admin/cron/?<?= htmlspecialchars(http_build_query($pageParams), ENT_QUOTES, 'UTF-8') ?>">Previous</a>
          <span class="muted">Page <?= $recentJobPage ?> of <?= $recentJobTotalPages ?></span>
          <?php
            $pageParams['page'] = min($recentJobTotalPages, $recentJobPage + 1);
          ?>
          <a class="btn ghost <?= $recentJobPage >= $recentJobTotalPages ? 'disabled' : '' ?>" href="<?= ($basePath ?: '') ?>/admin/cron/?<?= htmlspecialchars(http_build_query($pageParams), ENT_QUOTES, 'UTF-8') ?>">Next</a>
        </div>
      <?php endif; ?>
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
<script src="<?= ($basePath ?: '') ?>/assets/js/admin/cron.js" defer></script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/admin_layout.php';
