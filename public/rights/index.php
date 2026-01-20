<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$target = ($basePath ?: '') . '/admin/rights/';

http_response_code(302);
header('Location: ' . $target);
exit;
