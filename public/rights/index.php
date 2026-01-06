<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
header('Location: ' . ($basePath ?: '') . '/admin/rights/', true, 302);
exit;
