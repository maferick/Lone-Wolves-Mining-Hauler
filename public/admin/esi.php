<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

if (!$auth->isAdmin()) {
    http_response_code(403);
    exit('Forbidden');
}

$title = "Admin • ESI";
require __DIR__ . '/../../src/Views/admin/esi.php';
