<?php
declare(strict_types=1);

// Compatibility shim: "dbfunctions.php" is the canonical include name for DB operations.
// Internally we use App\Db\Db (src/Db/Db.php).
require_once __DIR__ . '/Db.php';
