<?php
declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../src/bootstrap.php';

api_require_key();

api_send_json($health);
