<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Db/Db.php';
require_once __DIR__ . '/../src/Services/AuthzService.php';

use App\Db\Db;
use App\Services\AuthzService;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db = new Db($pdo);
$authz = new AuthzService($db);

$pdo->exec(
  'CREATE TABLE app_user (
    user_id INTEGER PRIMARY KEY,
    corp_id INTEGER NULL,
    status TEXT NULL,
    session_revoked_at TEXT NULL,
    email TEXT NULL
  )'
);
$pdo->exec(
  'CREATE TABLE role (
    role_id INTEGER PRIMARY KEY,
    corp_id INTEGER NULL,
    role_key TEXT NOT NULL
  )'
);
$pdo->exec(
  'CREATE TABLE user_role (
    user_id INTEGER NOT NULL,
    role_id INTEGER NOT NULL
  )'
);
$pdo->exec(
  'CREATE TABLE audit_log (
    audit_id INTEGER PRIMARY KEY AUTOINCREMENT,
    corp_id INTEGER NULL,
    actor_user_id INTEGER NULL,
    actor_character_id INTEGER NULL,
    action TEXT NOT NULL,
    entity_table TEXT NULL,
    entity_pk TEXT NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    ip_address BLOB NULL,
    user_agent TEXT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
  )'
);

$pdo->exec("INSERT INTO role (role_id, corp_id, role_key) VALUES (10, 1, 'admin')");
$pdo->exec("INSERT INTO role (role_id, corp_id, role_key) VALUES (20, 1, 'subadmin')");
$pdo->exec("INSERT INTO app_user (user_id, corp_id, status, session_revoked_at, email) VALUES (1, 1, 'suspended', '2024-01-01 00:00:00', 'admin@example.com')");
$pdo->exec("INSERT INTO app_user (user_id, corp_id, status, session_revoked_at, email) VALUES (2, 1, 'disabled', '2024-01-01 00:00:00', 'disabled-admin@example.com')");
$pdo->exec("INSERT INTO app_user (user_id, corp_id, status, session_revoked_at, email) VALUES (3, 1, 'suspended', '2024-01-01 00:00:00', 'user@example.com')");
$pdo->exec("INSERT INTO app_user (user_id, corp_id, status, session_revoked_at, email) VALUES (4, 1, 'disabled', '2024-01-01 00:00:00', 'disabled-subadmin@example.com')");
$pdo->exec("INSERT INTO app_user (user_id, corp_id, status, session_revoked_at, email) VALUES (5, 1, 'suspended', '2024-01-01 00:00:00', 'subadmin@example.com')");
$pdo->exec('INSERT INTO user_role (user_id, role_id) VALUES (1, 10)');
$pdo->exec('INSERT INTO user_role (user_id, role_id) VALUES (2, 10)');
$pdo->exec('INSERT INTO user_role (user_id, role_id) VALUES (4, 20)');
$pdo->exec('INSERT INTO user_role (user_id, role_id) VALUES (5, 20)');

$remediated = $authz->selfHealAdminAccess('cron');
if ($remediated !== 2) {
  throw new RuntimeException('Expected exactly two admin-class users to be remediated.');
}

$admin = $db->one('SELECT status, session_revoked_at FROM app_user WHERE user_id = 1');
if (($admin['status'] ?? '') !== 'active') {
  throw new RuntimeException('Expected suspended admin to be reactivated.');
}
if (!empty($admin['session_revoked_at'])) {
  throw new RuntimeException('Expected suspended admin session_revoked_at to be cleared.');
}

$disabledAdmin = $db->one('SELECT status, session_revoked_at FROM app_user WHERE user_id = 2');
if (($disabledAdmin['status'] ?? '') !== 'disabled') {
  throw new RuntimeException('Expected disabled admin to remain disabled.');
}
if (($disabledAdmin['session_revoked_at'] ?? '') === '') {
  throw new RuntimeException('Expected disabled admin session_revoked_at to remain set.');
}

$nonAdmin = $db->one('SELECT status FROM app_user WHERE user_id = 3');
if (($nonAdmin['status'] ?? '') !== 'suspended') {
  throw new RuntimeException('Expected non-admin suspended user to remain suspended.');
}

$disabledSubadmin = $db->one('SELECT status, session_revoked_at FROM app_user WHERE user_id = 4');
if (($disabledSubadmin['status'] ?? '') !== 'disabled') {
  throw new RuntimeException('Expected disabled subadmin to remain disabled.');
}
if (($disabledSubadmin['session_revoked_at'] ?? '') === '') {
  throw new RuntimeException('Expected disabled subadmin session_revoked_at to remain set.');
}

$subadmin = $db->one('SELECT status, session_revoked_at FROM app_user WHERE user_id = 5');
if (($subadmin['status'] ?? '') !== 'active') {
  throw new RuntimeException('Expected suspended subadmin to be reactivated.');
}
if (!empty($subadmin['session_revoked_at'])) {
  throw new RuntimeException('Expected suspended subadmin session_revoked_at to be cleared.');
}

echo "Admin self-heal tests passed.\n";
