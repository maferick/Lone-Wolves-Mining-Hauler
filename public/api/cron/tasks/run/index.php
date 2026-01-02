<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../_helpers.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;
use App\Services\JobQueueService;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'esi.manage');

$data = api_read_json();
$taskKey = (string)($data['task_key'] ?? '');

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

switch ($taskKey) {
  case JobQueueService::CONTRACT_MATCH_JOB:
    if ($corpId <= 0) {
      api_send_json(['ok' => false, 'error' => 'Corp not available.'], 400);
    }
    if ($jobQueue->hasPendingJob($corpId, JobQueueService::CONTRACT_MATCH_JOB)) {
      api_send_json(['ok' => false, 'error' => 'Contract match already queued.'], 409);
    }
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
    if ($jobQueue->hasPendingJob(null, JobQueueService::WEBHOOK_DELIVERY_JOB)) {
      api_send_json(['ok' => false, 'error' => 'Webhook delivery already queued.'], 409);
    }
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
  case JobQueueService::CRON_TOKEN_REFRESH_JOB:
    if ($corpId <= 0) {
      api_send_json(['ok' => false, 'error' => 'Corp not available.'], 400);
    }
    if ($jobQueue->hasPendingJob($corpId, JobQueueService::CRON_TOKEN_REFRESH_JOB)) {
      api_send_json(['ok' => false, 'error' => 'Token refresh already queued.'], 409);
    }
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
    if ($jobQueue->hasPendingJob($corpId, JobQueueService::CRON_STRUCTURES_JOB)) {
      api_send_json(['ok' => false, 'error' => 'Structures sync already queued.'], 409);
    }
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
    if ($jobQueue->hasPendingJob($corpId, JobQueueService::CRON_PUBLIC_STRUCTURES_JOB)) {
      api_send_json(['ok' => false, 'error' => 'Public structures sync already queued.'], 409);
    }
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
    if ($jobQueue->hasPendingJob($corpId, JobQueueService::CRON_CONTRACTS_JOB)) {
      api_send_json(['ok' => false, 'error' => 'Contracts sync already queued.'], 409);
    }
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
