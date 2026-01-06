<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;
use App\Services\JobQueueService;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'esi.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);

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
    'description' => 'Pulls corp structures.',
    'runner' => 'task',
  ],
  JobQueueService::CRON_PUBLIC_STRUCTURES_JOB => [
    'key' => JobQueueService::CRON_PUBLIC_STRUCTURES_JOB,
    'name' => 'Public Structures',
    'interval' => $normalizeInterval((int)($intervalSettings[JobQueueService::CRON_PUBLIC_STRUCTURES_JOB] ?? 0), $publicStructuresInterval),
    'scope' => 'corp',
    'description' => 'Pulls public structures.',
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
  JobQueueService::CONTRACT_MATCH_JOB => [
    'key' => JobQueueService::CONTRACT_MATCH_JOB,
    'name' => 'Contract Matching',
    'interval' => $normalizeInterval((int)($intervalSettings[JobQueueService::CONTRACT_MATCH_JOB] ?? 0), $matchInterval),
    'scope' => 'corp',
    'description' => 'Matches contracts to hauling requests.',
    'runner' => 'task',
  ],
  JobQueueService::WEBHOOK_DELIVERY_JOB => [
    'key' => JobQueueService::WEBHOOK_DELIVERY_JOB,
    'name' => 'Webhook Delivery',
    'interval' => $normalizeInterval((int)($intervalSettings[JobQueueService::WEBHOOK_DELIVERY_JOB] ?? 0), $webhookInterval),
    'scope' => 'corp',
    'description' => 'Flushes queued webhook deliveries.',
    'runner' => 'task',
  ],
  JobQueueService::DISCORD_DELIVERY_JOB => [
    'key' => JobQueueService::DISCORD_DELIVERY_JOB,
    'name' => 'Discord Delivery',
    'interval' => $normalizeInterval((int)($intervalSettings[JobQueueService::DISCORD_DELIVERY_JOB] ?? 0), $discordInterval),
    'scope' => 'corp',
    'description' => 'Flushes queued Discord outbox notifications.',
    'runner' => 'task',
  ],
  JobQueueService::CRON_ALLIANCES_JOB => [
    'key' => JobQueueService::CRON_ALLIANCES_JOB,
    'name' => 'Alliances',
    'interval' => $normalizeInterval((int)($intervalSettings[JobQueueService::CRON_ALLIANCES_JOB] ?? 0), $alliancesInterval),
    'scope' => 'corp',
    'description' => 'Pulls alliance relationships.',
    'runner' => 'task',
  ],
  JobQueueService::CRON_NPC_STRUCTURES_JOB => [
    'key' => JobQueueService::CRON_NPC_STRUCTURES_JOB,
    'name' => 'NPC Structures',
    'interval' => $normalizeInterval((int)($intervalSettings[JobQueueService::CRON_NPC_STRUCTURES_JOB] ?? 0), $npcStructuresInterval),
    'scope' => 'corp',
    'description' => 'Pulls NPC structures.',
    'runner' => 'task',
  ],
  JobQueueService::WEBHOOK_REQUEUE_JOB => [
    'key' => JobQueueService::WEBHOOK_REQUEUE_JOB,
    'name' => 'Webhook Requeue',
    'interval' => $normalizeInterval((int)($intervalSettingsGlobal[JobQueueService::WEBHOOK_REQUEUE_JOB] ?? 0), $webhookRequeueInterval),
    'scope' => 'global',
    'description' => 'Requeues failed webhooks that are still enabled.',
    'runner' => 'task',
  ],
];

$taskSettingsCorp = $loadTaskSettings($db, $corpId);
$taskSettingsGlobal = $loadTaskSettings($db, null);

$statusClassMap = [
  'succeeded' => 'status-success',
  'failed' => 'status-failed',
  'dead' => 'status-dead',
  'running' => 'status-running',
  'queued' => 'status-queued',
];
$taskStates = [];

foreach ($taskDefinitions as $taskKey => $task) {
  $scopeCorpId = $task['scope'] === 'global' ? null : $corpId;
  $settings = $scopeCorpId === null ? $taskSettingsGlobal : $taskSettingsCorp;
  $enabled = $isTaskEnabled($settings, $taskKey);
  $lastJob = $fetchLastJob($db, $taskKey, $scopeCorpId);
  $lastStatus = $enabled ? ($lastJob['status'] ?? 'never') : 'disabled';
  $lastRun = $lastJob['finished_at'] ?? $lastJob['started_at'] ?? $lastJob['run_at'] ?? $lastJob['created_at'] ?? null;
  $statusClass = $statusClassMap[$lastStatus] ?? ($lastStatus === 'disabled' ? 'status-disabled' : 'status-never');

  $taskStates[$taskKey] = [
    'task' => $task,
    'enabled' => $enabled,
    'last_job' => $lastJob,
    'last_status' => $lastStatus,
    'last_run' => $lastRun,
    'status_class' => $statusClass,
  ];
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require __DIR__ . '/../../../../src/Views/partials/admin/cron_tasks.php';
