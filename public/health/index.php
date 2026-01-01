<?php
declare(strict_types=1);

// Standalone health endpoint (works even if routing rules are bypassed)
require_once __DIR__ . '/../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
echo json_encode($health, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
