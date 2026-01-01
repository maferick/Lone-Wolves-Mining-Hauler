<?php
declare(strict_types=1);

// Compatibility shim: "dbfunctions.php" is the canonical include name for DB operations.
// Internally we route to functionsdb.php (src/Db/functionsdb.php).
require_once __DIR__ . '/functionsdb.php';
