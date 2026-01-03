<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../_helpers.php';
require_once __DIR__ . '/../../../../../src/bootstrap.php';

api_require_key();

$payload = api_read_json();
$corpId = (int)($payload['corp_id'] ?? ($_POST['corp_id'] ?? ($_GET['corp_id'] ?? ($config['corp']['id'] ?? 0))));

if ($corpId <= 0) {
  api_send_json([
    'ok' => false,
    'error' => 'corp_id required',
  ], 400);
}

$targets = $payload['event_targets'] ?? ($_POST['event_targets'] ?? []);
$message = trim((string)($payload['webhook_test_message'] ?? ($_POST['webhook_test_message'] ?? 'Test notification from hauling.')));

if (!is_array($targets) || $targets === []) {
  api_send_json([
    'ok' => false,
    'error' => 'event_targets required',
  ], 400);
}

/** @var \App\Services\DiscordWebhookService $webhooks */
$webhooks = $services['discord_webhook'];

$results = [];
foreach ($targets as $eventKey => $targetId) {
  $eventKey = trim((string)$eventKey);
  if ($eventKey === '') {
    continue;
  }
  $target = is_scalar($targetId) ? trim((string)$targetId) : '';
  if ($target === '') {
    $results[$eventKey] = [
      'queued' => 0,
      'skipped' => true,
    ];
    continue;
  }

  $webhookId = null;
  if ($target !== 'all') {
    $webhookId = (int)$target;
    if ($webhookId <= 0) {
      $results[$eventKey] = [
        'queued' => 0,
        'skipped' => true,
      ];
      continue;
    }
  }

  $payload = $webhooks->buildTestPayloadForEvent($corpId, $eventKey, [
    'message' => $message,
  ]);
  $queued = $webhooks->enqueueTest($corpId, $eventKey, $payload, $webhookId);
  $results[$eventKey] = [
    'queued' => $queued,
    'webhook_id' => $webhookId,
  ];
}

api_send_json([
  'ok' => true,
  'results' => $results,
]);
