<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Db/Db.php';
require_once __DIR__ . '/../src/Services/AuthzService.php';

use App\Db\Db;
use App\Services\AuthzService;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db = new Db($pdo);

$pdo->exec("CREATE TABLE app_user (user_id INTEGER PRIMARY KEY, corp_id INTEGER, email TEXT, status TEXT, is_in_scope INTEGER)");
$pdo->exec("CREATE TABLE role (role_id INTEGER PRIMARY KEY, role_key TEXT)");
$pdo->exec("CREATE TABLE user_role (user_id INTEGER, role_id INTEGER)");
$pdo->exec("CREATE TABLE permission (perm_id INTEGER PRIMARY KEY, perm_key TEXT)");
$pdo->exec("CREATE TABLE role_permission (role_id INTEGER, perm_id INTEGER, allow INTEGER)");
$pdo->exec("CREATE TABLE app_setting (corp_id INTEGER, setting_key TEXT, setting_json TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE discord_user_link (user_id INTEGER, discord_user_id TEXT)");
$pdo->exec("CREATE TABLE discord_config (corp_id INTEGER, role_map_json TEXT, rights_source TEXT, rights_source_member TEXT, rights_source_hauler TEXT)");
$pdo->exec("CREATE TABLE discord_member_snapshot (corp_id INTEGER, role_id TEXT, member_json TEXT, scanned_at TEXT)");

$pdo->exec("INSERT INTO app_user (user_id, corp_id, email, status, is_in_scope) VALUES (1, 99, 'member@example.com', 'active', 1)");
$pdo->exec("INSERT INTO role (role_id, role_key) VALUES (1, 'member')");
$pdo->exec("INSERT INTO user_role (user_id, role_id) VALUES (1, 1)");
$pdo->exec("INSERT INTO permission (perm_id, perm_key) VALUES (1, 'hauling.member')");
$pdo->exec("INSERT INTO role_permission (role_id, perm_id, allow) VALUES (1, 1, 1)");

$authz = new AuthzService($db);
$user = [
  'user_id' => 1,
  'corp_id' => 99,
  'email' => 'member@example.com',
  'status' => 'active',
  'is_in_scope' => true,
];

$pdo->exec("INSERT INTO discord_config (corp_id, rights_source_member) VALUES (99, 'portal')");
$portalState = $authz->computeAccessState($user, ['hauling.member']);
if (empty($portalState['is_entitled'])) {
  throw new RuntimeException('Expected portal-leading entitlement to grant access.');
}

$pdo->exec("DELETE FROM discord_config");
$pdo->exec("INSERT INTO discord_config (corp_id, rights_source_member, role_map_json) VALUES (99, 'discord', '{\"hauling.member\":\"role-1\"}')");
$authz = new AuthzService($db);
$pdo->exec("INSERT INTO discord_user_link (user_id, discord_user_id) VALUES (1, 'discord-1')");
$snapshotFresh = json_encode([['discord_user_id' => 'discord-1']]);
$pdo->exec("INSERT INTO discord_member_snapshot (corp_id, role_id, member_json, scanned_at) VALUES (99, 'role-1', " . $pdo->quote($snapshotFresh) . ", '" . gmdate('c') . "')");

$discordState = $authz->computeAccessState($user);
if (empty($discordState['is_entitled'])) {
  throw new RuntimeException('Expected discord-leading entitlement to grant access.');
}

$pdo->exec("DELETE FROM discord_member_snapshot");
$staleSnapshot = json_encode([['discord_user_id' => 'discord-1']]);
$oldTime = gmdate('c', time() - (AuthzService::snapshotMaxAgeSeconds() + 120));
$pdo->exec("INSERT INTO discord_member_snapshot (corp_id, role_id, member_json, scanned_at) VALUES (99, 'role-1', " . $pdo->quote($staleSnapshot) . ", '" . $oldTime . "')");

$authz = new AuthzService($db);
$staleState = $authz->computeAccessState($user);
if (!empty($staleState['is_entitled'])) {
  throw new RuntimeException('Expected stale discord snapshot to fail closed.');
}

echo "Authz entitlement source tests passed.\n";
