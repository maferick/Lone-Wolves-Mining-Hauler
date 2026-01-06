<?php
declare(strict_types=1);

// Standalone terms endpoint (works even if routing rules are bypassed)
require_once __DIR__ . '/../../src/bootstrap.php';

$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Terms of Service';
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$docTitle = 'Terms of Service';
$docDescription = 'Discord integration terms for internal corp use.';
$docPath = __DIR__ . '/../../docs/TERMS.md';

require __DIR__ . '/../../src/Views/markdown-doc.php';
