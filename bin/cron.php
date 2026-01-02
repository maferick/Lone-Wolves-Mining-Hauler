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
 *  - CRON_SYNC_INTERVAL (seconds, default 300)
 *  - CRON_TOKEN_REFRESH_INTERVAL (seconds, default 300)
 *  - CRON_STRUCTURES_INTERVAL (seconds, default 900)
 *  - CRON_PUBLIC_STRUCTURES_INTERVAL (seconds, default 86400)
 *  - CRON_CONTRACTS_INTERVAL (seconds, default 300)
 *  - CRON_MATCH_INTERVAL (seconds, default 300)
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Db\Db;
use App\Services\JobQueueService;

$log = static function (string $message): void {
  $timestamp = gmdate('c');
  fwrite(STDOUT, "[{$timestamp}] {$message}\n");
};

$lockFile = $_ENV['CRON_LOCK_FILE'] ?? (sys_get_temp_dir() . '/lone_wolves_cron.lock');
$lockHandle = fopen($lockFile, 'c');
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
$syncInterval = (int)($_ENV['CRON_SYNC_INTERVAL'] ?? 300);
if ($syncInterval <= 0) {
  $syncInterval = 300;
}
$tokenRefreshInterval = (int)($_ENV['CRON_TOKEN_REFRESH_INTERVAL'] ?? 300);
if ($tokenRefreshInterval <= 0) {
  $tokenRefreshInterval = 300;
}
$structuresInterval = (int)($_ENV['CRON_STRUCTURES_INTERVAL'] ?? 900);
if ($structuresInterval <= 0) {
  $structuresInterval = 900;
}
$publicStructuresInterval = (int)($_ENV['CRON_PUBLIC_STRUCTURES_INTERVAL'] ?? 86400);
if ($publicStructuresInterval <= 0) {
  $publicStructuresInterval = 86400;
}
$contractsInterval = (int)($_ENV['CRON_CONTRACTS_INTERVAL'] ?? 300);
if ($contractsInterval <= 0) {
  $contractsInterval = 300;
}
$matchInterval = (int)($_ENV['CRON_MATCH_INTERVAL'] ?? 300);
if ($matchInterval <= 0) {
  $matchInterval = 300;
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
  $updated = false;

  if ($isTaskEnabled($taskSettings, JobQueueService::CRON_SYNC_JOB)
    && $shouldRun($state['cron_sync'] ?? null, $syncInterval, $now)) {
    try {
      if ($jobQueue->hasPendingJob($corpId, JobQueueService::CRON_SYNC_JOB)) {
        $log("Cron sync job already queued for corp {$corpId}.");
        $state['cron_sync'] = gmdate('c', $now);
        $updated = true;
      } else {
        $jobId = $jobQueue->enqueueCronSync($corpId, $charId, 'universe', false, false, false);
        $state['cron_sync'] = gmdate('c', $now);
        $updated = true;
        $log("Cron sync job queued for corp {$corpId}: {$jobId}.");
      }
    } catch (Throwable $e) {
      $log("Cron sync error for corp {$corpId}: {$e->getMessage()}");
    }
  }

  if ($isTaskEnabled($taskSettings, JobQueueService::CRON_TOKEN_REFRESH_JOB)
    && $shouldRun($state['token_refresh'] ?? null, $tokenRefreshInterval, $now)) {
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

  if ($isTaskEnabled($taskSettings, JobQueueService::CRON_STRUCTURES_JOB)
    && $shouldRun($state['structures'] ?? null, $structuresInterval, $now)) {
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

  if ($isTaskEnabled($taskSettings, JobQueueService::CRON_PUBLIC_STRUCTURES_JOB)
    && $shouldRun($state['public_structures'] ?? null, $publicStructuresInterval, $now)) {
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

  if ($isTaskEnabled($taskSettings, JobQueueService::CRON_CONTRACTS_JOB)
    && $shouldRun($state['contracts'] ?? null, $contractsInterval, $now)) {
    try {
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

  if ($isTaskEnabled($taskSettings, JobQueueService::CONTRACT_MATCH_JOB)
    && $shouldRun($state['contract_match'] ?? null, $matchInterval, $now)) {
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

  if ($updated) {
    $saveState($db, $corpId, $state);
  }
}
