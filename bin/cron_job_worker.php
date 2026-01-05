#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Db\Db;
use App\Services\ContractMatchService;
use App\Services\CronSyncService;
use App\Services\AllianceSyncService;
use App\Services\EsiClient;
use App\Services\JobQueueService;
use App\Services\NpcStructureSyncService;

function runCronJobWorker(Db $db, array $config, array $services, ?callable $logger = null): int
{
  $log = $logger ?? static function (string $message): void {
    fwrite(STDOUT, $message . "\n");
  };
  $formatException = static function (Throwable $e): string {
    $details = [];
    while ($e) {
      $details[] = sprintf(
        "%s: %s (code %s) in %s:%d\n%s",
        get_class($e),
        $e->getMessage(),
        $e->getCode(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
      );
      $e = $e->getPrevious();
      if ($e) {
        $details[] = "Caused by:";
      }
    }
    return implode("\n", $details);
  };

  $workerId = gethostname() . ':' . getmypid();
  $jobQueue = new JobQueueService($db);
  $job = $jobQueue->claimNextJob([
    JobQueueService::WEBHOOK_DELIVERY_JOB,
    JobQueueService::WEBHOOK_REQUEUE_JOB,
    JobQueueService::CRON_ALLIANCES_JOB,
    JobQueueService::CRON_NPC_STRUCTURES_JOB,
    JobQueueService::CRON_SYNC_JOB,
    JobQueueService::CRON_TOKEN_REFRESH_JOB,
    JobQueueService::CRON_STRUCTURES_JOB,
    JobQueueService::CRON_PUBLIC_STRUCTURES_JOB,
    JobQueueService::CRON_CONTRACTS_JOB,
    JobQueueService::CONTRACT_MATCH_JOB,
  ], $workerId);

  if (!$job) {
    $log('No queued cron jobs.');
    return 0;
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
  $log("Processing cron job {$jobId} ({$jobType}).");

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
      JobQueueService::CRON_ALLIANCES_JOB => [
        'label' => 'Starting alliance sync',
        'started' => 'cron.alliances.started',
        'completed' => 'cron.alliances.completed',
        'failed' => 'cron.alliances.failed',
        'log_start' => 'Alliance sync started.',
        'log_complete' => 'Alliance sync completed.',
        'log_failed_prefix' => 'Alliance sync failed',
      ],
      JobQueueService::CRON_NPC_STRUCTURES_JOB => [
        'label' => 'Starting NPC structures sync',
        'started' => 'cron.npc_structures.started',
        'completed' => 'cron.npc_structures.completed',
        'failed' => 'cron.npc_structures.failed',
        'log_start' => 'NPC structures sync started.',
        'log_complete' => 'NPC structures sync completed.',
        'log_failed_prefix' => 'NPC structures sync failed',
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
        $log("Webhook delivery job {$jobId} completed.");
        return 1;
      case JobQueueService::WEBHOOK_REQUEUE_JOB:
        if (!isset($services['discord_webhook'])) {
          throw new RuntimeException('Discord webhook service not configured.');
        }
        $limit = (int)($payload['limit'] ?? 200);
        $minutes = (int)($payload['minutes'] ?? 60);
        $jobQueue->updateProgress($jobId, [
          'current' => 0,
          'total' => 0,
          'label' => 'Requeuing failed webhooks',
          'stage' => 'start',
        ], 'Webhook requeue started.');
        $jobQueue->markStarted($jobId, 'cron.webhook_requeue.started');
        $result = $services['discord_webhook']->requeueFailedDeliveries($limit, $minutes);
        $jobQueue->markSucceeded($jobId, $result, 'cron.webhook_requeue.completed', 'Webhook requeue completed.');
        $log("Webhook requeue job {$jobId} completed.");
        return 1;
      case JobQueueService::CRON_ALLIANCES_JOB:
        $meta = $cronJobMeta[$jobType] ?? $cronJobMeta[JobQueueService::CRON_ALLIANCES_JOB];
        $jobQueue->updateProgress($jobId, [
          'current' => 0,
          'total' => 0,
          'label' => $meta['label'],
          'stage' => 'start',
        ], $meta['log_start']);
        $jobQueue->markStarted($jobId, $meta['started']);
        $allianceSync = new AllianceSyncService($db, $services['esi_client'] ?? new EsiClient($db, $config));
        $result = $allianceSync->syncAlliances(function (array $progress) use ($jobQueue, $jobId): void {
          $jobQueue->updateProgress(
            $jobId,
            $progress,
            $progress['label'] ?? null
          );
        });
        $jobQueue->markSucceeded($jobId, $result, $meta['completed'], $meta['log_complete']);
        $log("Alliance sync job {$jobId} completed.");
        return 1;
      case JobQueueService::CRON_NPC_STRUCTURES_JOB:
        $meta = $cronJobMeta[$jobType] ?? $cronJobMeta[JobQueueService::CRON_NPC_STRUCTURES_JOB];
        $jobQueue->updateProgress($jobId, [
          'current' => 0,
          'total' => 0,
          'label' => $meta['label'],
          'stage' => 'start',
        ], $meta['log_start']);
        $jobQueue->markStarted($jobId, $meta['started']);
        $npcSync = new NpcStructureSyncService($db, $config);
        $result = $npcSync->syncNpcStructures(function (array $progress) use ($jobQueue, $jobId): void {
          $jobQueue->updateProgress(
            $jobId,
            $progress,
            $progress['label'] ?? null
          );
        });
        $jobQueue->markSucceeded($jobId, $result, $meta['completed'], $meta['log_complete']);
        $log("NPC structures sync job {$jobId} completed.");
        return 1;
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
        $log("Contract match job {$jobId} completed.");
        return 1;
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
        $log("Cron sync job {$jobId} completed.");
        return 1;
      default:
        throw new RuntimeException("Unhandled job type: {$jobType}");
    }
  } catch (Throwable $e) {
    $errorDetail = $formatException($e);
    switch ($jobType) {
      case JobQueueService::WEBHOOK_DELIVERY_JOB:
        $jobQueue->markFailed(
          $jobId,
          $e->getMessage(),
          ['error_detail' => $errorDetail],
          'cron.webhook_delivery.failed',
          "Webhook delivery failed: {$e->getMessage()}"
        );
        break;
      case JobQueueService::WEBHOOK_REQUEUE_JOB:
        $jobQueue->markFailed(
          $jobId,
          $e->getMessage(),
          ['error_detail' => $errorDetail],
          'cron.webhook_requeue.failed',
          "Webhook requeue failed: {$e->getMessage()}"
        );
        break;
      case JobQueueService::CONTRACT_MATCH_JOB:
        $jobQueue->markFailed(
          $jobId,
          $e->getMessage(),
          ['error_detail' => $errorDetail],
          'cron.contract_match.failed',
          "Contract match failed: {$e->getMessage()}"
        );
        break;
      case JobQueueService::CRON_SYNC_JOB:
      case JobQueueService::CRON_TOKEN_REFRESH_JOB:
      case JobQueueService::CRON_STRUCTURES_JOB:
      case JobQueueService::CRON_PUBLIC_STRUCTURES_JOB:
      case JobQueueService::CRON_CONTRACTS_JOB:
      case JobQueueService::CRON_ALLIANCES_JOB:
      case JobQueueService::CRON_NPC_STRUCTURES_JOB:
        $meta = $cronJobMeta[$jobType] ?? $cronJobMeta[JobQueueService::CRON_SYNC_JOB];
        $jobQueue->markFailed(
          $jobId,
          $e->getMessage(),
          ['error_detail' => $errorDetail],
          $meta['failed'],
          "{$meta['log_failed_prefix']}: {$e->getMessage()}"
        );
        break;
      default:
        $jobQueue->markFailed(
          $jobId,
          $e->getMessage(),
          ['error_detail' => $errorDetail],
          'cron.job.failed',
          "Job failed: {$e->getMessage()}"
        );
        break;
    }
    $log("Cron job {$jobId} failed: {$errorDetail}");
    return 2;
  }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
  $result = runCronJobWorker($db, $config, $services);
  exit($result === 2 ? 1 : 0);
}
