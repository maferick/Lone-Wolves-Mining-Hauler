<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;
use App\Services\JobQueueService;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requireAdmin($authCtx);

$corpId = (int)($authCtx['corp_id'] ?? 0);
$data = api_read_json();
$scope = (string)($data['scope'] ?? 'all');
$useSde = !empty($data['sde']);
if ($scope === 'sde') {
  $scope = 'universe';
  $useSde = true;
}
if (!in_array($scope, ['all', 'universe'], true)) {
  $scope = 'all';
}
$force = !empty($data['force']);

$cronSetting = $db->one(
  "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'esi.cron' LIMIT 1",
  ['cid' => $corpId]
);
$cronJson = $cronSetting ? Db::jsonDecode($cronSetting['setting_json'], []) : [];
$cronCharId = (int)($cronJson['character_id'] ?? 0);

if ($cronCharId <= 0 && $scope !== 'universe') {
  api_send_json(['ok' => false, 'error' => 'Set a cron character before running the sync.'], 400);
}

$jobQueue = new JobQueueService($db);
$jobId = $jobQueue->enqueueCronSync($corpId, $cronCharId, $scope, $force, $useSde, false, [
  'actor_user_id' => $authCtx['user_id'] ?? null,
  'actor_character_id' => $authCtx['character_id'] ?? null,
  'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
  'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
]);

api_send_json([
  'ok' => true,
  'job_id' => $jobId,
  'status' => 'queued',
  'time_utc' => gmdate('c'),
]);
