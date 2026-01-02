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
  JobQueueService::CRON_TOKEN_REFRESH_JOB,
  JobQueueService::CRON_STRUCTURES_JOB,
  JobQueueService::CRON_PUBLIC_STRUCTURES_JOB,
  JobQueueService::CRON_CONTRACTS_JOB,
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
  $cronJobMeta = [
    JobQueueService::CRON_SYNC_JOB => [
      'label' => 'Starting ESI sync',
      'started' => 'cron.sync.started',
      'completed' => 'cron.sync.completed',
      'failed' => 'cron.sync.failed',
      'log_start' => 'ESI sync started.',
      'log_complete' => 'ESI sync completed.',
      'log_failed_prefix' => 'ESI sync failed',
    ],
    JobQueueService::CRON_TOKEN_REFRESH_JOB => [
      'label' => 'Starting token refresh',
      'started' => 'cron.token_refresh.started',
      'completed' => 'cron.token_refresh.completed',
      'failed' => 'cron.token_refresh.failed',
      'log_start' => 'Token refresh started.',
      'log_complete' => 'Token refresh completed.',
      'log_failed_prefix' => 'Token refresh failed',
    ],
    JobQueueService::CRON_STRUCTURES_JOB => [
      'label' => 'Starting structures sync',
      'started' => 'cron.structures.started',
      'completed' => 'cron.structures.completed',
      'failed' => 'cron.structures.failed',
      'log_start' => 'Structures sync started.',
      'log_complete' => 'Structures sync completed.',
      'log_failed_prefix' => 'Structures sync failed',
    ],
    JobQueueService::CRON_PUBLIC_STRUCTURES_JOB => [
      'label' => 'Starting public structures sync',
      'started' => 'cron.public_structures.started',
      'completed' => 'cron.public_structures.completed',
      'failed' => 'cron.public_structures.failed',
      'log_start' => 'Public structures sync started.',
      'log_complete' => 'Public structures sync completed.',
      'log_failed_prefix' => 'Public structures sync failed',
    ],
    JobQueueService::CRON_CONTRACTS_JOB => [
      'label' => 'Starting contracts sync',
      'started' => 'cron.contracts.started',
      'completed' => 'cron.contracts.completed',
      'failed' => 'cron.contracts.failed',
      'log_start' => 'Contracts sync started.',
      'log_complete' => 'Contracts sync completed.',
      'log_failed_prefix' => 'Contracts sync failed',
    ],
  ];

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
    case JobQueueService::CRON_TOKEN_REFRESH_JOB:
    case JobQueueService::CRON_STRUCTURES_JOB:
    case JobQueueService::CRON_PUBLIC_STRUCTURES_JOB:
    case JobQueueService::CRON_CONTRACTS_JOB:
      if ($corpId <= 0) {
        throw new RuntimeException('Cron sync job missing corp id.');
      }
      $charId = (int)($payload['character_id'] ?? 0);
      $scope = (string)($payload['scope'] ?? 'all');
      $force = !empty($payload['force']);
      $useSde = !empty($payload['sde']);
      $syncPublicStructures = !empty($payload['sync_public_structures']);
      $steps = $payload['steps'] ?? null;
      $requiresCharacter = $scope !== 'universe';
      if (is_array($steps)) {
        $requiresCharacter = array_intersect($steps, [
          'token_refresh',
          'structures',
          'public_structures',
          'contracts',
        ]) !== [];
      }
      if ($requiresCharacter && $charId <= 0) {
        throw new RuntimeException('Cron sync job missing character id.');
      }

      $meta = $cronJobMeta[$jobType] ?? $cronJobMeta[JobQueueService::CRON_SYNC_JOB];
      $cronService = new CronSyncService($db, $config, $services['esi'], $services['discord_webhook'] ?? null);
      $jobQueue->updateProgress($jobId, [
        'current' => 0,
        'total' => 0,
        'label' => $meta['label'],
        'stage' => 'start',
      ], $meta['log_start']);
      $jobQueue->markStarted($jobId, $meta['started']);

      $result = $cronService->run($corpId, $charId, [
        'force' => $force,
        'scope' => $scope,
        'sde' => $useSde,
        'steps' => $steps,
        'sync_public_structures' => $syncPublicStructures,
        'on_progress' => function (array $progress) use ($jobQueue, $jobId): void {
          $jobQueue->updateProgress(
            $jobId,
            $progress,
            $progress['label'] ?? null
          );
        },
      ]);

      $jobQueue->markSucceeded($jobId, $result, $meta['completed'], $meta['log_complete']);
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
    case JobQueueService::CRON_TOKEN_REFRESH_JOB:
    case JobQueueService::CRON_STRUCTURES_JOB:
    case JobQueueService::CRON_PUBLIC_STRUCTURES_JOB:
    case JobQueueService::CRON_CONTRACTS_JOB:
      $meta = $cronJobMeta[$jobType] ?? $cronJobMeta[JobQueueService::CRON_SYNC_JOB];
      $jobQueue->markFailed(
        $jobId,
        $e->getMessage(),
        [],
        $meta['failed'],
        "{$meta['log_failed_prefix']}: {$e->getMessage()}"
      );
      break;
    default:
      $jobQueue->markFailed($jobId, $e->getMessage(), [], 'cron.job.failed', "Job failed: {$e->getMessage()}");
      break;
  }
  fwrite(STDERR, "Cron job {$jobId} failed: {$e->getMessage()}\n");
  exit(1);
}
