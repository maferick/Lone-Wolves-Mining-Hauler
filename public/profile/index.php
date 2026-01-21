<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$appName = $config['app']['name'];
$title = $appName . ' • Profile';
$basePathForViews = rtrim((string)($config['app']['base_path'] ?? ''), '/');

\App\Auth\Auth::requireLogin($authCtx);
\App\Auth\Auth::requireAccess($authCtx, 'profile');

require __DIR__ . '/../../src/Views/profile.php';
