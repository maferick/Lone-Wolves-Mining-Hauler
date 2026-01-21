<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Db/Db.php';
require_once __DIR__ . '/../src/Auth/Auth.php';
require_once __DIR__ . '/../src/Services/AuthzService.php';

use App\Db\Db;
use App\Auth\Auth;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db = new Db($pdo);

$pdo->exec("CREATE TABLE app_user (user_id INTEGER PRIMARY KEY, corp_id INTEGER, character_id INTEGER, character_name TEXT, display_name TEXT, email TEXT, status TEXT, session_revoked_at TEXT, is_in_scope INTEGER)");
$pdo->exec("CREATE TABLE role (role_id INTEGER PRIMARY KEY, role_key TEXT)");
$pdo->exec("CREATE TABLE user_role (user_id INTEGER, role_id INTEGER)");
$pdo->exec("CREATE TABLE permission (perm_id INTEGER PRIMARY KEY, perm_key TEXT)");
$pdo->exec("CREATE TABLE role_permission (role_id INTEGER, perm_id INTEGER, allow INTEGER)");
$pdo->exec("CREATE TABLE app_setting (corp_id INTEGER, setting_key TEXT, setting_json TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE discord_user_link (user_id INTEGER, discord_user_id TEXT)");
$pdo->exec("CREATE TABLE discord_config (corp_id INTEGER, role_map_json TEXT, rights_source TEXT, rights_source_member TEXT, rights_source_hauler TEXT)");
$pdo->exec("CREATE TABLE discord_member_snapshot (corp_id INTEGER, role_id TEXT, member_json TEXT, scanned_at TEXT)");

$pdo->exec("INSERT INTO app_user (user_id, corp_id, display_name, email, status) VALUES (1, 1, 'Admin Alt', 'admin@example.com', 'active')");
$pdo->exec("INSERT INTO role (role_id, role_key) VALUES (1, 'admin')");
$pdo->exec("INSERT INTO user_role (user_id, role_id) VALUES (1, 1)");
$pdo->exec("INSERT INTO discord_config (corp_id, rights_source_member) VALUES (1, 'discord')");

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['logged_in_at'] = time();

$ctx = Auth::context($db);

if (($ctx['user_id'] ?? null) !== 1) {
  throw new RuntimeException('Expected user_id to be 1.');
}
if (!($ctx['is_authenticated'] ?? false)) {
  throw new RuntimeException('Expected user to be authenticated.');
}
if (!($ctx['is_admin'] ?? false)) {
  throw new RuntimeException('Expected admin to be recognized.');
}
if (($ctx['is_entitled'] ?? true) !== false) {
  throw new RuntimeException('Expected admin to be not entitled without Discord role.');
}
if (Auth::userId() !== 1) {
  throw new RuntimeException('Expected session to remain valid.');
}

echo "Auth context admin bypass test passed.\n";
