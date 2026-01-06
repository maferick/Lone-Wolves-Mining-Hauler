<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Services\CronSyncService;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'esi.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);

$cronService = new CronSyncService($db, $config, $services['esi'], $services['discord_webhook'] ?? null);
$cronStats = $cronService->getStats($corpId);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require __DIR__ . '/../../../../src/Views/partials/admin/cron_last_sync.php';
