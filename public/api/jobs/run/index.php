<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../src/bootstrap.php';

api_require_key();

// Stub: later this will pop job_queue and execute runners
api_send_json([
  'ok' => true,
  'message' => 'job runner wired (stub)',
  'time_utc' => gmdate('c'),
]);
