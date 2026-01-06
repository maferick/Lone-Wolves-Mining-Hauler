<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../bootstrap.php';

api_require_key();

/** @var \App\Services\DiscordWebhookService $webhooks */
$webhooks = $services['discord_webhook'];
$result = $webhooks->sendPending(25);

api_send_json([
  'ok' => true,
  'message' => 'job runner dispatched webhooks',
  'result' => $result,
  'time_utc' => gmdate('c'),
]);
