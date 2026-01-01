<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use App\Auth\Auth;

Auth::logout();
$base = rtrim((string)($config['app']['base_path'] ?? ''), '/');
http_response_code(302);
header('Location: ' . ($base ?: '') . '/');
exit;
