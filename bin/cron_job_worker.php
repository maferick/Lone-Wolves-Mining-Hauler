#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Db\Db;
use App\Services\ContractMatchService;
use App\Services\CronSyncService;
use App\Services\JobQueueService;

$workerId = gethostname() . ':' . getmypid();
$jobQueue = new JobQueueService($db);
$job = $jobQueue->claimNextJob([
  JobQueueService::WEBHOOK_DELIVERY_JOB,
  JobQueueService::CRON_SYNC_JOB,
  JobQueueService::CONTRACT_MATCH_JOB,
], $workerId);

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
$jobType = (string)($job['job_type'] ?? '');

try {
  switch ($jobType) {
    case JobQueueService::WEBHOOK_DELIVERY_JOB:
      if (!isset($services['discord_webhook'])) {
        throw new RuntimeException('Discord webhook service not configured.');
      }
      $limit = (int)($payload['limit'] ?? 50);
      $jobQueue->updateProgress($jobId, [
        'current' => 0,
        'total' => 0,
        'label' => 'Sending webhooks',
        'stage' => 'start',
      ], 'Webhook delivery started.');
      $jobQueue->markStarted($jobId, 'cron.webhook_delivery.started');
      $result = $services['discord_webhook']->sendPending($limit);
      $jobQueue->markSucceeded($jobId, $result, 'cron.webhook_delivery.completed', 'Webhook delivery completed.');
      fwrite(STDOUT, "Webhook delivery job {$jobId} completed.\n");
      exit(0);
    case JobQueueService::CONTRACT_MATCH_JOB:
      if ($corpId <= 0) {
        throw new RuntimeException('Contract match job missing corp id.');
      }
      $jobQueue->updateProgress($jobId, [
        'current' => 0,
        'total' => 0,
        'label' => 'Matching contracts',
        'stage' => 'start',
      ], 'Contract match started.');
      $jobQueue->markStarted($jobId, 'cron.contract_match.started');
      $matcher = new ContractMatchService($db, $config, $services['discord_webhook'] ?? null);
      $result = $matcher->matchOpenRequests($corpId);
      $jobQueue->markSucceeded($jobId, $result, 'cron.contract_match.completed', 'Contract match completed.');
      fwrite(STDOUT, "Contract match job {$jobId} completed.\n");
      exit(0);
    case JobQueueService::CRON_SYNC_JOB:
      $charId = (int)($payload['character_id'] ?? 0);
      $scope = (string)($payload['scope'] ?? 'all');
      $force = !empty($payload['force']);
      $useSde = !empty($payload['sde']);
      $syncPublicStructures = !empty($payload['sync_public_structures']);

      $cronService = new CronSyncService($db, $config, $services['esi'], $services['discord_webhook'] ?? null);
      $jobQueue->updateProgress($jobId, [
        'current' => 0,
        'total' => 0,
        'label' => 'Starting sync',
        'stage' => 'start',
      ], 'Cron sync started.');
      $jobQueue->markStarted($jobId);

      $result = $cronService->run($corpId, $charId, [
        'force' => $force,
        'scope' => $scope,
        'sde' => $useSde,
        'sync_public_structures' => $syncPublicStructures,
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
    default:
      throw new RuntimeException("Unhandled job type: {$jobType}");
  }
} catch (Throwable $e) {
  switch ($jobType) {
    case JobQueueService::WEBHOOK_DELIVERY_JOB:
      $jobQueue->markFailed($jobId, $e->getMessage(), [], 'cron.webhook_delivery.failed', "Webhook delivery failed: {$e->getMessage()}");
      break;
    case JobQueueService::CONTRACT_MATCH_JOB:
      $jobQueue->markFailed($jobId, $e->getMessage(), [], 'cron.contract_match.failed', "Contract match failed: {$e->getMessage()}");
      break;
    case JobQueueService::CRON_SYNC_JOB:
      $jobQueue->markFailed($jobId, $e->getMessage());
      break;
    default:
      $jobQueue->markFailed($jobId, $e->getMessage(), [], 'cron.job.failed', "Job failed: {$e->getMessage()}");
      break;
  }
  fwrite(STDERR, "Cron job {$jobId} failed: {$e->getMessage()}\n");
  exit(1);
}
