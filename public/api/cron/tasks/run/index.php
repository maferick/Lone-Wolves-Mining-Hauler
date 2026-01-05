<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../_helpers.php';
require_once __DIR__ . '/../../../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;
use App\Services\JobQueueService;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'esi.manage');

$data = api_read_json();
$taskKey = trim((string)($data['task_key'] ?? ($data['taskKey'] ?? ($data['task'] ?? ''))));
$taskKey = rtrim($taskKey, ';');
$taskKey = strtolower($taskKey);
$taskKey = preg_replace('/[^a-z0-9._-]/', '', (string)$taskKey);
$force = (int)($data['force'] ?? ($_GET['force'] ?? 0)) === 1;

$jobQueue = new JobQueueService($db);
$corpId = (int)($authCtx['corp_id'] ?? 0);
$cronCharId = 0;

if (in_array($taskKey, [
  JobQueueService::CRON_TOKEN_REFRESH_JOB,
  JobQueueService::CRON_STRUCTURES_JOB,
  JobQueueService::CRON_PUBLIC_STRUCTURES_JOB,
  JobQueueService::CRON_CONTRACTS_JOB,
], true)) {
  $cronSetting = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'esi.cron' LIMIT 1",
    ['cid' => $corpId]
  );
  $cronJson = $cronSetting ? Db::jsonDecode($cronSetting['setting_json'], []) : [];
  $cronCharId = (int)($cronJson['character_id'] ?? 0);
  if ($cronCharId <= 0) {
    api_send_json(['ok' => false, 'error' => 'Set a cron character before running the task.'], 400);
  }
}

$auditContext = [
  'actor_user_id' => $authCtx['user_id'] ?? null,
  'actor_character_id' => $authCtx['character_id'] ?? null,
  'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
  'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
];

$handlePendingJob = static function (
  JobQueueService $jobQueue,
  ?int $corpId,
  string $jobType,
  bool $force,
  string $label
) use ($db, $auditContext): void {
  $pendingJob = $jobQueue->getPendingJob($corpId, $jobType);
  if (!$pendingJob) {
    return;
  }
  $jobId = (int)$pendingJob['job_id'];
  $status = (string)($pendingJob['status'] ?? '');
  $updatedAt = $pendingJob['updated_at'] ?? null;
  $startedAt = $pendingJob['started_at'] ?? null;
  $lockedAt = $pendingJob['locked_at'] ?? null;

  if (!$force) {
    api_send_json(['ok' => false, 'error' => "{$label} already queued."], 409);
  }

  $thresholdSeconds = 1200;
  if ($status !== 'running') {
    api_send_json(['ok' => false, 'error' => "{$label} already queued and not running."], 409);
  }
  $reference = $updatedAt ?: ($lockedAt ?: $startedAt);
  $ageSeconds = $reference ? (time() - (int)strtotime((string)$reference)) : $thresholdSeconds + 1;
  if ($ageSeconds < $thresholdSeconds) {
    api_send_json(
      ['ok' => false, 'error' => "{$label} already queued and is still running. Try again later."],
      409
    );
  }

  $jobQueue->markFailed(
    $jobId,
    'Force-restarted by admin',
    [],
    'cron.force_restart',
    "{$label} force-restarted by admin."
  );
};

switch ($taskKey) {
  case JobQueueService::CONTRACT_MATCH_JOB:
    if ($corpId <= 0) {
      api_send_json(['ok' => false, 'error' => 'Corp not available.'], 400);
    }
    $handlePendingJob($jobQueue, $corpId, JobQueueService::CONTRACT_MATCH_JOB, $force, 'Contract match');
    $jobId = $jobQueue->enqueueContractMatch($corpId, $auditContext);
    api_send_json([
      'ok' => true,
      'job_id' => $jobId,
      'status' => 'queued',
      'time_utc' => gmdate('c'),
    ]);
    break;
  case JobQueueService::WEBHOOK_DELIVERY_JOB:
    if (!isset($services['discord_webhook'])) {
      api_send_json(['ok' => false, 'error' => 'Webhook service not configured.'], 400);
    }
    $handlePendingJob($jobQueue, null, JobQueueService::WEBHOOK_DELIVERY_JOB, $force, 'Webhook delivery');
    $limit = (int)($_ENV['CRON_WEBHOOK_LIMIT'] ?? 50);
    if ($limit <= 0) {
      $limit = 50;
    }
    $jobId = $jobQueue->enqueueWebhookDelivery($limit, $auditContext);
    api_send_json([
      'ok' => true,
      'job_id' => $jobId,
      'status' => 'queued',
      'time_utc' => gmdate('c'),
    ]);
    break;
  case JobQueueService::WEBHOOK_REQUEUE_JOB:
    if (!isset($services['discord_webhook'])) {
      api_send_json(['ok' => false, 'error' => 'Webhook service not configured.'], 400);
    }
    $handlePendingJob($jobQueue, null, JobQueueService::WEBHOOK_REQUEUE_JOB, $force, 'Webhook requeue');
    $limit = (int)($_ENV['CRON_WEBHOOK_REQUEUE_LIMIT'] ?? 200);
    if ($limit <= 0) {
      $limit = 200;
    }
    $jobId = $jobQueue->enqueueWebhookRequeue($limit, 60, $auditContext);
    api_send_json([
      'ok' => true,
      'job_id' => $jobId,
      'status' => 'queued',
      'time_utc' => gmdate('c'),
    ]);
    break;
  case JobQueueService::CRON_ALLIANCES_JOB:
    $handlePendingJob($jobQueue, null, JobQueueService::CRON_ALLIANCES_JOB, $force, 'Alliance sync');
    $jobId = $jobQueue->enqueueAllianceSync($auditContext);
    api_send_json([
      'ok' => true,
      'job_id' => $jobId,
      'status' => 'queued',
      'time_utc' => gmdate('c'),
    ]);
    break;
  case JobQueueService::CRON_NPC_STRUCTURES_JOB:
    $handlePendingJob($jobQueue, null, JobQueueService::CRON_NPC_STRUCTURES_JOB, $force, 'NPC structures sync');
    $jobId = $jobQueue->enqueueNpcStructuresSync($auditContext);
    api_send_json([
      'ok' => true,
      'job_id' => $jobId,
      'status' => 'queued',
      'time_utc' => gmdate('c'),
    ]);
    break;
  case JobQueueService::CRON_TOKEN_REFRESH_JOB:
    if ($corpId <= 0) {
      api_send_json(['ok' => false, 'error' => 'Corp not available.'], 400);
    }
    $handlePendingJob($jobQueue, $corpId, JobQueueService::CRON_TOKEN_REFRESH_JOB, $force, 'Token refresh');
    $jobId = $jobQueue->enqueueTokenRefresh($corpId, $cronCharId, $auditContext);
    api_send_json([
      'ok' => true,
      'job_id' => $jobId,
      'status' => 'queued',
      'time_utc' => gmdate('c'),
    ]);
    break;
  case JobQueueService::CRON_STRUCTURES_JOB:
    if ($corpId <= 0) {
      api_send_json(['ok' => false, 'error' => 'Corp not available.'], 400);
    }
    $handlePendingJob($jobQueue, $corpId, JobQueueService::CRON_STRUCTURES_JOB, $force, 'Structures sync');
    $jobId = $jobQueue->enqueueStructuresSync($corpId, $cronCharId, $auditContext);
    api_send_json([
      'ok' => true,
      'job_id' => $jobId,
      'status' => 'queued',
      'time_utc' => gmdate('c'),
    ]);
    break;
  case JobQueueService::CRON_PUBLIC_STRUCTURES_JOB:
    if ($corpId <= 0) {
      api_send_json(['ok' => false, 'error' => 'Corp not available.'], 400);
    }
    $handlePendingJob($jobQueue, $corpId, JobQueueService::CRON_PUBLIC_STRUCTURES_JOB, $force, 'Public structures sync');
    $jobId = $jobQueue->enqueuePublicStructuresSync($corpId, $cronCharId, $auditContext);
    api_send_json([
      'ok' => true,
      'job_id' => $jobId,
      'status' => 'queued',
      'time_utc' => gmdate('c'),
    ]);
    break;
  case JobQueueService::CRON_CONTRACTS_JOB:
    if ($corpId <= 0) {
      api_send_json(['ok' => false, 'error' => 'Corp not available.'], 400);
    }
    $handlePendingJob($jobQueue, $corpId, JobQueueService::CRON_CONTRACTS_JOB, $force, 'Contracts sync');
    $jobId = $jobQueue->enqueueContractsSync($corpId, $cronCharId, $auditContext);
    api_send_json([
      'ok' => true,
      'job_id' => $jobId,
      'status' => 'queued',
      'time_utc' => gmdate('c'),
    ]);
    break;
  default:
    api_send_json(['ok' => false, 'error' => 'Unknown task.'], 400);
}
