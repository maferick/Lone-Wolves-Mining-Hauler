#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/cron.php
 *
 * Run every minute. It schedules and runs all other cron tasks.
 *
 * Env overrides:
 *  - CRON_WEBHOOK_LIMIT (default 50)
 *  - CRON_WEBHOOK_REQUEUE_LIMIT (default 200)
 *  - CRON_WEBHOOK_REQUEUE_INTERVAL (seconds, default 900)
 *  - CRON_SYNC_INTERVAL (seconds, default 300)
 *  - CRON_TOKEN_REFRESH_INTERVAL (seconds, default 300)
 *  - CRON_STRUCTURES_INTERVAL (seconds, default 900)
 *  - CRON_PUBLIC_STRUCTURES_INTERVAL (seconds, default 86400)
 *  - CRON_CONTRACTS_INTERVAL (seconds, default 300)
 *  - CRON_ALLIANCES_INTERVAL (seconds, default 86400)
 *  - CRON_NPC_STRUCTURES_INTERVAL (seconds, default 86400)
 *  - CRON_MATCH_INTERVAL (seconds, default 300)
 *
 * Corp overrides:
 *  - app_setting key "cron.intervals" (JSON: task_key => interval seconds, minimum 60).
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Db\Db;
use App\Services\JobQueueService;

$log = static function (string $message): void {
  $timestamp = gmdate('c');
  fwrite(STDOUT, "[{$timestamp}] {$message}\n");
};

$lockFile = $_ENV['CRON_LOCK_FILE'] ?? null;
$lockCandidates = $lockFile ? [$lockFile] : [
  sys_get_temp_dir() . '/lone_wolves_cron.lock',
  __DIR__ . '/../tmp/lone_wolves_cron.lock',
];
$lockHandle = false;
foreach ($lockCandidates as $candidate) {
  $dir = dirname($candidate);
  if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
  }
  $handle = @fopen($candidate, 'c');
  if ($handle !== false) {
    $lockFile = $candidate;
    $lockHandle = $handle;
    break;
  }
}
if ($lockHandle === false) {
  $log("Unable to open cron lock file: {$lockFile}");
  exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
  $log('Cron already running; exiting.');
  exit(0);
}
ftruncate($lockHandle, 0);
fwrite($lockHandle, (string)getmypid());
register_shutdown_function(static function () use ($lockHandle): void {
  flock($lockHandle, LOCK_UN);
  fclose($lockHandle);
});

$webhookLimit = (int)($_ENV['CRON_WEBHOOK_LIMIT'] ?? 50);
if ($webhookLimit <= 0) {
  $webhookLimit = 50;
}
$discordLimit = (int)($_ENV['CRON_DISCORD_LIMIT'] ?? 50);
if ($discordLimit <= 0) {
  $discordLimit = 50;
}
$webhookRequeueLimit = (int)($_ENV['CRON_WEBHOOK_REQUEUE_LIMIT'] ?? 200);
if ($webhookRequeueLimit <= 0) {
  $webhookRequeueLimit = 200;
}
$normalizeInterval = static function (int $value, int $fallback): int {
  $interval = $value > 0 ? $value : $fallback;
  return max(60, $interval);
};
$webhookRequeueInterval = $normalizeInterval((int)($_ENV['CRON_WEBHOOK_REQUEUE_INTERVAL'] ?? 900), 900);
$syncInterval = $normalizeInterval((int)($_ENV['CRON_SYNC_INTERVAL'] ?? 300), 300);
$tokenRefreshInterval = $normalizeInterval((int)($_ENV['CRON_TOKEN_REFRESH_INTERVAL'] ?? 300), 300);
$structuresInterval = $normalizeInterval((int)($_ENV['CRON_STRUCTURES_INTERVAL'] ?? 900), 900);
$publicStructuresInterval = $normalizeInterval((int)($_ENV['CRON_PUBLIC_STRUCTURES_INTERVAL'] ?? 86400), 86400);
$contractsInterval = $normalizeInterval((int)($_ENV['CRON_CONTRACTS_INTERVAL'] ?? 300), 300);
$contractsMaxRuntime = (int)($_ENV['CRON_CONTRACTS_MAX_RUNTIME'] ?? 60);
$contractsMaxRuntime = max(10, $contractsMaxRuntime);
$alliancesInterval = $normalizeInterval((int)($_ENV['CRON_ALLIANCES_INTERVAL'] ?? 86400), 86400);
$npcStructuresInterval = $normalizeInterval((int)($_ENV['CRON_NPC_STRUCTURES_INTERVAL'] ?? 86400), 86400);
$matchInterval = $normalizeInterval((int)($_ENV['CRON_MATCH_INTERVAL'] ?? 300), 300);
$discordOnboardIntervalPortal = $normalizeInterval((int)($_ENV['CRON_DISCORD_ONBOARD_PORTAL_INTERVAL'] ?? 3600), 3600);
$discordOnboardIntervalDiscord = $normalizeInterval((int)($_ENV['CRON_DISCORD_ONBOARD_DISCORD_INTERVAL'] ?? 300), 300);
$workerLimit = (int)($_ENV['CRON_WORKER_LIMIT'] ?? 3);
if ($workerLimit <= 0) {
  $workerLimit = 3;
}

$now = time();
$jobQueue = new JobQueueService($db);

$loadState = static function (Db $db, int $corpId): array {
  $row = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'cron.scheduler' LIMIT 1",
    ['cid' => $corpId]
  );
  if (!$row || empty($row['setting_json'])) {
    return [];
  }
  $decoded = Db::jsonDecode((string)$row['setting_json'], []);
  return is_array($decoded) ? $decoded : [];
};

$loadIntervals = static function (Db $db, int $corpId): array {
  $row = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'cron.intervals' LIMIT 1",
    ['cid' => $corpId]
  );
  if (!$row || empty($row['setting_json'])) {
    return [];
  }
  $decoded = Db::jsonDecode((string)$row['setting_json'], []);
  return is_array($decoded) ? $decoded : [];
};

$saveState = static function (Db $db, int $corpId, array $state): void {
  $db->execute(
    "INSERT INTO app_setting (corp_id, setting_key, setting_json)
     VALUES (:cid, 'cron.scheduler', :json)
     ON DUPLICATE KEY UPDATE setting_json = VALUES(setting_json), updated_at = UTC_TIMESTAMP()",
    [
      'cid' => $corpId,
      'json' => Db::jsonEncode($state),
    ]
  );
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

$isTaskEnabled = static function (array $settings, string $taskKey): bool {
  if (!array_key_exists($taskKey, $settings)) {
    return true;
  }
  return (bool)$settings[$taskKey];
};

$shouldRun = static function (?string $lastRun, int $intervalSeconds, int $now): bool {
  if (!$lastRun) {
    return true;
  }
  $ts = strtotime($lastRun);
  if ($ts === false) {
    return true;
  }
  return ($now - $ts) >= $intervalSeconds;
};
$shouldRunGlobal = static function (Db $db, string $jobType, int $intervalSeconds, int $now): bool {
  $row = $db->one(
    "SELECT COALESCE(MAX(finished_at), MAX(started_at), MAX(created_at)) AS last_run
       FROM job_queue
      WHERE job_type = :job_type
        AND status IN ('running','succeeded','failed','dead')",
    ['job_type' => $jobType]
  );
  $lastRun = $row ? (string)($row['last_run'] ?? '') : '';
  if ($lastRun === '') {
    return true;
  }
  $ts = strtotime($lastRun);
  if ($ts === false) {
    return true;
  }
  return ($now - $ts) >= $intervalSeconds;
};

$globalTaskSettings = $loadTaskSettings($db, null);

try {
  if (!$isTaskEnabled($globalTaskSettings, JobQueueService::WEBHOOK_DELIVERY_JOB)) {
    $log('Webhook delivery disabled; skipping.');
  } elseif (!isset($services['discord_webhook'])) {
    $log('Discord webhook service not configured.');
  } else {
    if ($jobQueue->hasPendingJob(null, JobQueueService::WEBHOOK_DELIVERY_JOB)) {
      $log('Webhook delivery job already queued.');
    } else {
      $jobId = $jobQueue->enqueueWebhookDelivery($webhookLimit);
      $log("Webhook delivery job queued: {$jobId}.");
    }
  }
} catch (Throwable $e) {
  $log("Webhook delivery error: {$e->getMessage()}");
}

try {
  if (!$isTaskEnabled($globalTaskSettings, JobQueueService::DISCORD_DELIVERY_JOB)) {
    $log('Discord delivery disabled; skipping.');
  } elseif (!isset($services['discord_delivery'])) {
    $log('Discord delivery service not configured.');
  } else {
    if ($jobQueue->hasPendingJob(null, JobQueueService::DISCORD_DELIVERY_JOB)) {
      $log('Discord delivery job already queued.');
    } else {
      $jobId = $jobQueue->enqueueDiscordDelivery($discordLimit);
      $log("Discord delivery job queued: {$jobId}.");
    }
  }
} catch (Throwable $e) {
  $log("Discord delivery error: {$e->getMessage()}");
}

try {
  if (!$isTaskEnabled($globalTaskSettings, JobQueueService::CRON_ALLIANCES_JOB)) {
    $log('Alliance sync disabled; skipping.');
  } elseif ($jobQueue->hasPendingJob(null, JobQueueService::CRON_ALLIANCES_JOB)) {
    $log('Alliance sync job already queued.');
  } elseif (!$shouldRunGlobal($db, JobQueueService::CRON_ALLIANCES_JOB, $alliancesInterval, $now)) {
    $log('Alliance sync interval not reached; skipping.');
  } else {
    $jobId = $jobQueue->enqueueAllianceSync();
    $log("Alliance sync job queued: {$jobId}.");
  }
} catch (Throwable $e) {
  $log("Alliance sync error: {$e->getMessage()}");
}

try {
  if (!$isTaskEnabled($globalTaskSettings, JobQueueService::CRON_NPC_STRUCTURES_JOB)) {
    $log('NPC structures sync disabled; skipping.');
  } elseif ($jobQueue->hasPendingJob(null, JobQueueService::CRON_NPC_STRUCTURES_JOB)) {
    $log('NPC structures sync job already queued.');
  } elseif (!$shouldRunGlobal($db, JobQueueService::CRON_NPC_STRUCTURES_JOB, $npcStructuresInterval, $now)) {
    $log('NPC structures sync interval not reached; skipping.');
  } else {
    $jobId = $jobQueue->enqueueNpcStructuresSync();
    $log("NPC structures sync job queued: {$jobId}.");
  }
} catch (Throwable $e) {
  $log("NPC structures sync error: {$e->getMessage()}");
}

try {
  if (!$isTaskEnabled($globalTaskSettings, JobQueueService::WEBHOOK_REQUEUE_JOB)) {
    $log('Webhook requeue disabled; skipping.');
  } elseif (!isset($services['discord_webhook'])) {
    $log('Discord webhook service not configured.');
  } elseif ($jobQueue->hasPendingJob(null, JobQueueService::WEBHOOK_REQUEUE_JOB)) {
    $log('Webhook requeue job already queued.');
  } elseif (!$shouldRunGlobal($db, JobQueueService::WEBHOOK_REQUEUE_JOB, $webhookRequeueInterval, $now)) {
    $log('Webhook requeue interval not reached; skipping.');
  } else {
    $jobId = $jobQueue->enqueueWebhookRequeue($webhookRequeueLimit, 60);
    $log("Webhook requeue job queued: {$jobId}.");
  }
} catch (Throwable $e) {
  $log("Webhook requeue error: {$e->getMessage()}");
}

$cronRows = $db->select(
  "SELECT corp_id, setting_json
     FROM app_setting
    WHERE setting_key = 'esi.cron'"
);

if ($cronRows === []) {
  $log('No cron corp settings found.');
}

foreach ($cronRows as $row) {
  $corpId = (int)($row['corp_id'] ?? 0);
  $settings = Db::jsonDecode((string)($row['setting_json'] ?? ''), []);
  if (!is_array($settings)) {
    $settings = [];
  }
  $charId = (int)($settings['character_id'] ?? 0);
  if ($corpId <= 0 || $charId <= 0) {
    continue;
  }

  $state = $loadState($db, $corpId);
  $taskSettings = $loadTaskSettings($db, $corpId);
  $intervalSettings = $loadIntervals($db, $corpId);
  $updated = false;
  $corpSyncInterval = $normalizeInterval((int)($intervalSettings[JobQueueService::CRON_SYNC_JOB] ?? 0), $syncInterval);
  $corpTokenRefreshInterval = $normalizeInterval((int)($intervalSettings[JobQueueService::CRON_TOKEN_REFRESH_JOB] ?? 0), $tokenRefreshInterval);
  $corpStructuresInterval = $normalizeInterval((int)($intervalSettings[JobQueueService::CRON_STRUCTURES_JOB] ?? 0), $structuresInterval);
  $corpPublicStructuresInterval = $normalizeInterval((int)($intervalSettings[JobQueueService::CRON_PUBLIC_STRUCTURES_JOB] ?? 0), $publicStructuresInterval);
  $corpContractsInterval = $normalizeInterval((int)($intervalSettings[JobQueueService::CRON_CONTRACTS_JOB] ?? 0), $contractsInterval);
  $corpMatchInterval = $normalizeInterval((int)($intervalSettings[JobQueueService::CONTRACT_MATCH_JOB] ?? 0), $matchInterval);
  $discordOnboardTaskKey = 'discord.members.onboard';
  $discordConfigRow = $db->one(
    "SELECT rights_source
       FROM discord_config
      WHERE corp_id = :cid
      LIMIT 1",
    ['cid' => $corpId]
  );
  $rightsSource = (string)($discordConfigRow['rights_source'] ?? 'portal');
  $discordOnboardFallback = $rightsSource === 'discord'
    ? $discordOnboardIntervalDiscord
    : $discordOnboardIntervalPortal;
  $corpDiscordOnboardInterval = $normalizeInterval(
    (int)($intervalSettings[$discordOnboardTaskKey] ?? 0),
    $discordOnboardFallback
  );

  if (!$isTaskEnabled($taskSettings, JobQueueService::CRON_SYNC_JOB)) {
    $log("Cron sync disabled for corp {$corpId}; skipping.");
  } elseif ($shouldRun($state['cron_sync'] ?? null, $corpSyncInterval, $now)) {
    try {
      if ($jobQueue->hasPendingJob($corpId, JobQueueService::CRON_SYNC_JOB)) {
        $log("Cron sync job already queued for corp {$corpId}.");
        $state['cron_sync'] = gmdate('c', $now);
        $updated = true;
      } else {
        $jobId = $jobQueue->enqueueCronSync($corpId, $charId, 'universe', false, true, false);
        $state['cron_sync'] = gmdate('c', $now);
        $updated = true;
        $log("Cron sync job queued for corp {$corpId}: {$jobId}.");
      }
    } catch (Throwable $e) {
      $log("Cron sync error for corp {$corpId}: {$e->getMessage()}");
    }
  }

  if (!$isTaskEnabled($taskSettings, JobQueueService::CRON_TOKEN_REFRESH_JOB)) {
    $log("Token refresh disabled for corp {$corpId}; skipping.");
  } elseif ($shouldRun($state['token_refresh'] ?? null, $corpTokenRefreshInterval, $now)) {
    try {
      if ($jobQueue->hasPendingJob($corpId, JobQueueService::CRON_TOKEN_REFRESH_JOB)) {
        $log("Token refresh job already queued for corp {$corpId}.");
        $state['token_refresh'] = gmdate('c', $now);
        $updated = true;
      } else {
        $jobId = $jobQueue->enqueueTokenRefresh($corpId, $charId);
        $state['token_refresh'] = gmdate('c', $now);
        $updated = true;
        $log("Token refresh job queued for corp {$corpId}: {$jobId}.");
      }
    } catch (Throwable $e) {
      $log("Token refresh error for corp {$corpId}: {$e->getMessage()}");
    }
  }

  if (!$isTaskEnabled($taskSettings, JobQueueService::CRON_STRUCTURES_JOB)) {
    $log("Structures sync disabled for corp {$corpId}; skipping.");
  } elseif ($shouldRun($state['structures'] ?? null, $corpStructuresInterval, $now)) {
    try {
      if ($jobQueue->hasPendingJob($corpId, JobQueueService::CRON_STRUCTURES_JOB)) {
        $log("Structures sync job already queued for corp {$corpId}.");
        $state['structures'] = gmdate('c', $now);
        $updated = true;
      } else {
        $jobId = $jobQueue->enqueueStructuresSync($corpId, $charId);
        $state['structures'] = gmdate('c', $now);
        $updated = true;
        $log("Structures sync job queued for corp {$corpId}: {$jobId}.");
      }
    } catch (Throwable $e) {
      $log("Structures sync error for corp {$corpId}: {$e->getMessage()}");
    }
  }

  if (!$isTaskEnabled($taskSettings, JobQueueService::CRON_PUBLIC_STRUCTURES_JOB)) {
    $log("Public structures sync disabled for corp {$corpId}; skipping.");
  } elseif ($shouldRun($state['public_structures'] ?? null, $corpPublicStructuresInterval, $now)) {
    try {
      if ($jobQueue->hasPendingJob($corpId, JobQueueService::CRON_PUBLIC_STRUCTURES_JOB)) {
        $log("Public structures sync job already queued for corp {$corpId}.");
        $state['public_structures'] = gmdate('c', $now);
        $updated = true;
      } else {
        $jobId = $jobQueue->enqueuePublicStructuresSync($corpId, $charId);
        $state['public_structures'] = gmdate('c', $now);
        $updated = true;
        $log("Public structures sync job queued for corp {$corpId}: {$jobId}.");
      }
    } catch (Throwable $e) {
      $log("Public structures sync error for corp {$corpId}: {$e->getMessage()}");
    }
  }

  if (!$isTaskEnabled($taskSettings, JobQueueService::CRON_CONTRACTS_JOB)) {
    $log("Contracts sync disabled for corp {$corpId}; skipping.");
  } elseif ($shouldRun($state['contracts'] ?? null, $corpContractsInterval, $now)) {
    try {
      $staleJobId = $jobQueue->expireStaleRunningJob(
        $corpId,
        JobQueueService::CRON_CONTRACTS_JOB,
        $contractsMaxRuntime,
        "Contracts sync exceeded {$contractsMaxRuntime}s and was marked stale.",
        'cron.contracts.stale'
      );
      if ($staleJobId) {
        $log("Contracts sync job {$staleJobId} marked stale for corp {$corpId}.");
      }
      if ($jobQueue->hasPendingJob($corpId, JobQueueService::CRON_CONTRACTS_JOB)) {
        $log("Contracts sync job already queued for corp {$corpId}.");
        $state['contracts'] = gmdate('c', $now);
        $updated = true;
      } else {
        $jobId = $jobQueue->enqueueContractsSync($corpId, $charId);
        $state['contracts'] = gmdate('c', $now);
        $updated = true;
        $log("Contracts sync job queued for corp {$corpId}: {$jobId}.");
      }
    } catch (Throwable $e) {
      $log("Contracts sync error for corp {$corpId}: {$e->getMessage()}");
    }
  }

  if (!$isTaskEnabled($taskSettings, JobQueueService::CONTRACT_MATCH_JOB)) {
    $log("Contract match disabled for corp {$corpId}; skipping.");
  } elseif ($shouldRun($state['contract_match'] ?? null, $corpMatchInterval, $now)) {
    try {
      if ($jobQueue->hasPendingJob($corpId, JobQueueService::CONTRACT_MATCH_JOB)) {
        $log("Contract match job already queued for corp {$corpId}.");
        $state['contract_match'] = gmdate('c', $now);
        $updated = true;
      } else {
        $jobId = $jobQueue->enqueueContractMatch($corpId);
        $state['contract_match'] = gmdate('c', $now);
        $updated = true;
        $log("Contract match job queued for corp {$corpId}: {$jobId}.");
      }
    } catch (Throwable $e) {
      $log("Contract match error for corp {$corpId}: {$e->getMessage()}");
    }
  }

  if ($discordConfigRow) {
    if (!$isTaskEnabled($taskSettings, $discordOnboardTaskKey)) {
      $log("Discord onboarding scan disabled for corp {$corpId}; skipping.");
    } elseif ($shouldRun($state['discord_members_onboard'] ?? null, $corpDiscordOnboardInterval, $now)) {
      try {
        if (!isset($services['discord_events'])) {
          $log('Discord event service not configured.');
        } elseif (!isset($services['discord_delivery'])) {
          $log('Discord delivery service not configured.');
        } else {
          $services['discord_events']->enqueueAdminTask(
            $corpId,
            'discord.members.onboard',
            ['run_key' => gmdate('c', $now)]
          );
          $state['discord_members_onboard'] = gmdate('c', $now);
          $updated = true;
          $log("Discord onboarding scan queued for corp {$corpId}.");
        }
      } catch (Throwable $e) {
        $log("Discord onboarding scan error for corp {$corpId}: {$e->getMessage()}");
      }
    }
  }

  if ($updated) {
    $saveState($db, $corpId, $state);
  }
}

require_once __DIR__ . '/cron_job_worker.php';

$processed = 0;
for ($i = 0; $i < $workerLimit; $i++) {
  $result = runCronJobWorker($db, $config, $services, $log);
  if ($result === 0) {
    break;
  }
  $processed++;
  if ($result === 2) {
    $log('Cron worker encountered an error; stopping.');
    break;
  }
}

$log("Cron worker processed {$processed} job(s).");
