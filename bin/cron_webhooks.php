#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/cron_webhooks.php
 *
 * Usage:
 *   php bin/cron_webhooks.php --limit=50
 */

require __DIR__ . '/../src/bootstrap.php';

$opts = getopt('', ['limit::']);
$limit = isset($opts['limit']) ? (int)$opts['limit'] : 50;
$limit = $limit > 0 ? min($limit, 100) : 50;

try {
  /** @var \App\Services\DiscordWebhookService $webhooks */
  $webhooks = $services['discord_webhook'];
  $result = $webhooks->sendPending($limit);
  echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
  exit(0);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
  exit(1);
}
