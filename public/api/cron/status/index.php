<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;
use App\Services\JobQueueService;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'esi.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);
$data = api_read_json();
$jobId = (int)($data['job_id'] ?? 0);

if ($jobId <= 0) {
  api_send_json(['ok' => false, 'error' => 'job_id required'], 400);
}

$jobQueue = new JobQueueService($db);
$job = $jobQueue->getJobForCorp($jobId, $corpId);

if (!$job) {
  api_send_json(['ok' => false, 'error' => 'Job not found'], 404);
}

$payload = [];
if (!empty($job['payload_json'])) {
  $decoded = Db::jsonDecode((string)$job['payload_json'], []);
  if (is_array($decoded)) {
    $payload = $decoded;
  }
}

api_send_json([
  'ok' => true,
  'job_id' => (int)$job['job_id'],
  'status' => $job['status'],
  'attempt' => (int)$job['attempt'],
  'started_at' => $job['started_at'],
  'finished_at' => $job['finished_at'],
  'last_error' => $job['last_error'],
  'payload' => $payload,
]);
