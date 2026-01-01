<?php
declare(strict_types=1);

// Standalone rates endpoint (works even if routing rules are bypassed)
require_once __DIR__ . '/../../src/bootstrap.php';

$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Rates';
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$body = '<section class="card"><div class="card-header"><h2>Rates</h2><p class="muted">Coming soon.</p></div><div class="content"><a class="btn ghost" href="' . htmlspecialchars(($basePath ?: '') . '/', ENT_QUOTES, 'UTF-8') . '">Back to dashboard</a></div></section>';

require __DIR__ . '/../../src/Views/layout.php';
