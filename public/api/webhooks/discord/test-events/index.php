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
$hasTargets = false;
foreach ($targets as $eventKey => $targetId) {
  $eventKey = trim((string)$eventKey);
  if ($eventKey === '') {
    continue;
  }
  $targetList = [];
  if (is_array($targetId)) {
    $targetList = $targetId;
  } elseif (is_scalar($targetId)) {
    $targetList = [$targetId];
  }

  if ($targetList === []) {
    $results[$eventKey] = [
      'queued' => 0,
      'skipped' => true,
    ];
    continue;
  }

  $queuedTotal = 0;
  $targetIds = [];
  foreach ($targetList as $targetValue) {
    $target = is_scalar($targetValue) ? trim((string)$targetValue) : '';
    if ($target === '') {
      continue;
    }
    $hasTargets = true;
    if ($target === 'all') {
      $payload = $webhooks->buildTestPayloadForEvent($corpId, $eventKey, [
        'message' => $message,
      ]);
      $queuedTotal += $webhooks->enqueueTest($corpId, $eventKey, $payload, null);
      $targetIds[] = 'all';
      continue;
    }

    $webhookId = (int)$target;
    if ($webhookId <= 0) {
      continue;
    }
    $payload = $webhooks->buildTestPayloadForEvent($corpId, $eventKey, [
      'message' => $message,
    ]);
    $queuedTotal += $webhooks->enqueueTest($corpId, $eventKey, $payload, $webhookId);
    $targetIds[] = $webhookId;
  }

  if ($queuedTotal === 0 && $targetIds === []) {
    $results[$eventKey] = [
      'queued' => 0,
      'skipped' => true,
    ];
    continue;
  }

  $results[$eventKey] = [
    'queued' => $queuedTotal,
    'targets' => $targetIds,
  ];
}

if (!$hasTargets) {
  api_send_json([
    'ok' => false,
    'error' => 'event_targets required',
  ], 400);
}

api_send_json([
  'ok' => true,
  'results' => $results,
]);
