<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../_helpers.php';
require_once __DIR__ . '/../../../../../src/bootstrap.php';

api_require_key();

$payload = api_read_json();
$webhookId = (int)($payload['webhook_id'] ?? ($_GET['webhook_id'] ?? 0));
$corpId = (int)($payload['corp_id'] ?? ($_GET['corp_id'] ?? ($config['corp']['id'] ?? 0)));
$message = trim((string)($payload['message'] ?? ($_GET['message'] ?? 'Test notification from hauling.')));

if ($corpId <= 0 && $webhookId > 0 && $db !== null) {
  $row = $db->one(
    "SELECT corp_id FROM discord_webhook WHERE webhook_id = :wid LIMIT 1",
    ['wid' => $webhookId]
  );
  if ($row) {
    $corpId = (int)$row['corp_id'];
  }
}

if ($corpId <= 0) {
  api_send_json([
    'ok' => false,
    'error' => 'corp_id required',
  ], 400);
}

/** @var \App\Services\DiscordWebhookService $webhooks */
$webhooks = $services['discord_webhook'];

$discordPayload = [
  'username' => (string)($config['app']['name'] ?? 'Lone Wolves Hauling'),
  'content' => $message !== '' ? $message : 'Test notification from hauling.',
];

$queued = $webhooks->enqueue($corpId, 'webhook.test', $discordPayload, $webhookId ?: null);

api_send_json([
  'ok' => true,
  'queued' => $queued,
]);
