<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../src/bootstrap.php';

api_require_key();

// Stub endpoint: later this will accept an internal event payload and enqueue webhook_delivery
$payload = api_read_json();

api_send_json([
  'ok' => true,
  'message' => 'webhook endpoint wired (stub)',
  'received_keys' => array_keys($payload),
]);
