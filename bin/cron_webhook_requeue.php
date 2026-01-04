#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/cron_webhook_requeue.php
 *
 * Usage:
 *   php bin/cron_webhook_requeue.php --limit=200 --minutes=60
 */

require __DIR__ . '/../src/bootstrap.php';

$opts = getopt('', ['limit::', 'minutes::']);
$limit = isset($opts['limit']) ? (int)$opts['limit'] : 200;
$limit = $limit > 0 ? min($limit, 500) : 200;
$minutes = isset($opts['minutes']) ? (int)$opts['minutes'] : 60;
$minutes = $minutes > 0 ? min($minutes, 1440) : 60;

try {
  /** @var \App\Services\DiscordWebhookService $webhooks */
  $webhooks = $services['discord_webhook'];
  $result = $webhooks->requeueFailedDeliveries($limit, $minutes);
  echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
  exit(0);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
  exit(1);
}
