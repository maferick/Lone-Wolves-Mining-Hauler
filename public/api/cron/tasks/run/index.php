<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../_helpers.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Services\JobQueueService;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'esi.manage');

$data = api_read_json();
$taskKey = (string)($data['task_key'] ?? '');

$jobQueue = new JobQueueService($db);
$corpId = (int)($authCtx['corp_id'] ?? 0);

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
  default:
    api_send_json(['ok' => false, 'error' => 'Unknown task.'], 400);
}
