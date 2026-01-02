<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../src/bootstrap.php';

api_require_key();

$payload = api_read_json();
$limit = (int)($payload['limit'] ?? ($_GET['limit'] ?? 25));
$limit = $limit > 0 ? min($limit, 50) : 25;

/** @var \App\Services\DiscordWebhookService $webhooks */
$webhooks = $services['discord_webhook'];
$result = $webhooks->sendPending($limit);

api_send_json([
  'ok' => true,
  'result' => $result,
  'time_utc' => gmdate('c'),
]);
