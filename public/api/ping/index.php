<?php
declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../src/bootstrap.php';

api_require_key();

api_send_json([
  'ok' => true,
  'service' => $config['app']['name'] ?? 'Corp Hauling',
  'time_utc' => gmdate('c'),
  'base_path' => $config['app']['base_path'] ?? '',
  'env' => $config['app']['env'] ?? 'dev',
]);
