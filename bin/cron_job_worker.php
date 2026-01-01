#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Db\Db;
use App\Services\CronSyncService;
use App\Services\JobQueueService;

$workerId = gethostname() . ':' . getmypid();
$jobQueue = new JobQueueService($db);
$job = $jobQueue->claimNextCronSync($workerId);

if (!$job) {
  fwrite(STDOUT, "No queued cron jobs.\n");
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

$cronService = new CronSyncService($db, $config, $services['esi']);
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
  fwrite(STDOUT, "Cron sync job {$jobId} completed.\n");
  exit(0);
} catch (Throwable $e) {
  $jobQueue->markFailed($jobId, $e->getMessage());
  fwrite(STDERR, "Cron sync job {$jobId} failed: {$e->getMessage()}\n");
  exit(1);
}
