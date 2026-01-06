<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../bootstrap.php';

api_require_key();

/** @var \App\Services\DiscordWebhookService $webhooks */
$webhooks = $services['discord_webhook'];

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$limit = $limit > 0 ? min($limit, 50) : 10;
$result = $webhooks->sendPending($limit);

api_send_json([
  'ok' => true,
  'result' => $result,
]);
