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
 *  - CRON_MATCH_INTERVAL (seconds, default 300)
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Db\Db;
use App\Services\ContractMatchService;
use App\Services\CronSyncService;
use App\Services\JobQueueService;

$webhookLimit = (int)($_ENV['CRON_WEBHOOK_LIMIT'] ?? 50);
if ($webhookLimit <= 0) {
  $webhookLimit = 50;
}
$syncInterval = (int)($_ENV['CRON_SYNC_INTERVAL'] ?? 300);
if ($syncInterval <= 0) {
  $syncInterval = 300;
}
$matchInterval = (int)($_ENV['CRON_MATCH_INTERVAL'] ?? 300);
if ($matchInterval <= 0) {
  $matchInterval = 300;
}

$now = time();

$log = static function (string $message): void {
  $timestamp = gmdate('c');
  fwrite(STDOUT, "[{$timestamp}] {$message}\n");
};

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

try {
  if (!isset($services['discord_webhook'])) {
    $log('Discord webhook service not configured.');
  } else {
    $result = $services['discord_webhook']->sendPending($webhookLimit);
    $log("Webhook delivery processed: {$result['processed']} (sent {$result['sent']}, failed {$result['failed']}, pending {$result['pending']}).");
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
  $updated = false;

  if ($shouldRun($state['cron_sync'] ?? null, $syncInterval, $now)) {
    try {
      $cron = new CronSyncService($db, $config, $services['esi'], $services['discord_webhook'] ?? null);
      $cron->run($corpId, $charId, ['scope' => 'all']);
      $state['cron_sync'] = gmdate('c', $now);
      $updated = true;
      $log("Cron sync ran for corp {$corpId}.");
    } catch (Throwable $e) {
      $log("Cron sync error for corp {$corpId}: {$e->getMessage()}");
    }
  }

  if ($shouldRun($state['contract_match'] ?? null, $matchInterval, $now)) {
    try {
      $matcher = new ContractMatchService($db, $config, $services['discord_webhook'] ?? null);
      $result = $matcher->matchOpenRequests($corpId);
      $state['contract_match'] = gmdate('c', $now);
      $updated = true;
      $log("Contract match ran for corp {$corpId}: matched {$result['matched']}, mismatched {$result['mismatched']}, completed {$result['completed']}.");
    } catch (Throwable $e) {
      $log("Contract match error for corp {$corpId}: {$e->getMessage()}");
    }
  }

  if ($updated) {
    $saveState($db, $corpId, $state);
  }
}

$jobQueue = new JobQueueService($db);
$workerId = gethostname() . ':' . getmypid();
$job = $jobQueue->claimNextCronSync($workerId);
if (!$job) {
  $log('No queued cron sync jobs.');
  exit(0);
}

$jobId = (int)$job['job_id'];
$payload = [];
if (!empty($job['payload_json'])) {
  $decoded = Db::jsonDecode((string)$job['payload_json'], []);
  if (is_array($decoded)) {
    $payload = $decoded;
  }
}

$corpId = (int)($job['corp_id'] ?? 0);
$charId = (int)($payload['character_id'] ?? 0);
$scope = (string)($payload['scope'] ?? 'all');
$force = !empty($payload['force']);
$useSde = !empty($payload['sde']);

$cronService = new CronSyncService($db, $config, $services['esi'], $services['discord_webhook'] ?? null);
$jobQueue->updateProgress($jobId, [
  'current' => 0,
  'total' => 0,
  'label' => 'Starting sync',
  'stage' => 'start',
], 'Cron sync started.');
$jobQueue->markStarted($jobId);

try {
  $result = $cronService->run($corpId, $charId, [
    'force' => $force,
    'scope' => $scope,
    'sde' => $useSde,
    'on_progress' => function (array $progress) use ($jobQueue, $jobId): void {
      $jobQueue->updateProgress(
        $jobId,
        $progress,
        $progress['label'] ?? null
      );
    },
  ]);

  $jobQueue->markSucceeded($jobId, $result);
  $log("Cron sync job {$jobId} completed.");
  exit(0);
} catch (Throwable $e) {
  $jobQueue->markFailed($jobId, $e->getMessage());
  $log("Cron sync job {$jobId} failed: {$e->getMessage()}");
  exit(1);
}
