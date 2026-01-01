<?php
declare(strict_types=1);

// Standalone docs endpoint (works even if routing rules are bypassed)
require_once __DIR__ . '/../../src/bootstrap.php';

$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Docs';
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');

require __DIR__ . '/../../src/Views/docs.php';
