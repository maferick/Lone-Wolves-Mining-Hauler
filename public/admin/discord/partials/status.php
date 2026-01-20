<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requireAdmin($authCtx);

$corpId = (int)($authCtx['corp_id'] ?? 0);

$loadConfig = static function (Db $db, int $corpId): array {
  $row = $db->one(
    "SELECT * FROM discord_config WHERE corp_id = :cid LIMIT 1",
    ['cid' => $corpId]
  );

  return $row ? $row : [
    'corp_id' => $corpId,
    'enabled_webhooks' => 1,
    'enabled_bot' => 0,
    'application_id' => (string)($GLOBALS['config']['discord']['application_id'] ?? ''),
    'public_key' => (string)($GLOBALS['config']['discord']['public_key'] ?? ''),
    'guild_id' => (string)($GLOBALS['config']['discord']['guild_id'] ?? ''),
    'rate_limit_per_minute' => 20,
    'dedupe_window_seconds' => 60,
    'commands_ephemeral_default' => 1,
    'channel_mode' => 'threads',
    'hauling_channel_id' => '',
    'requester_thread_access' => 'read_only',
    'auto_thread_create_on_request' => 0,
    'thread_auto_archive_minutes' => 1440,
    'auto_archive_on_complete' => 1,
    'auto_lock_on_complete' => 1,
    'role_map_json' => null,
    'last_bot_action_at' => null,
    'bot_permissions_test_json' => null,
    'bot_permissions_test_at' => null,
  ];
};

$configRow = $loadConfig($db, $corpId);
$roleMap = [];
if (!empty($configRow['role_map_json'])) {
  try {
    $decoded = Db::jsonDecode((string)$configRow['role_map_json'], []);
    if (is_array($decoded)) {
      $roleMap = $decoded;
    }
  } catch (Throwable $e) {
    $roleMap = [];
  }
}

$botTokenConfigured = !empty($config['discord']['bot_token']);
$publicKeyConfigured = !empty($configRow['public_key']) || !empty($config['discord']['public_key']);
$roleMappingCount = count(array_filter($roleMap, static fn($value) => is_string($value) && trim($value) !== ''));

$pendingCount = (int)($db->fetchValue(
  "SELECT COUNT(*) FROM discord_outbox WHERE corp_id = :cid AND status IN ('queued','failed','sending')",
  ['cid' => $corpId]
) ?? 0);
$lastSent = (string)($db->fetchValue(
  "SELECT MAX(sent_at) FROM discord_outbox WHERE corp_id = :cid AND status = 'sent'",
  ['cid' => $corpId]
) ?? '');
$lastErrorRow = $db->one(
  "SELECT last_error FROM discord_outbox WHERE corp_id = :cid AND status = 'failed' ORDER BY updated_at DESC LIMIT 1",
  ['cid' => $corpId]
);
$lastError = $lastErrorRow ? (string)($lastErrorRow['last_error'] ?? '') : '';
$permissionTestRow = $db->one(
  "SELECT status, last_error, created_at, sent_at
     FROM discord_outbox
    WHERE corp_id = :cid
      AND event_key = 'discord.bot.permissions_test'
    ORDER BY outbox_id DESC
    LIMIT 1",
  ['cid' => $corpId]
);
$permissionTest = null;
$permissionTestTone = 'warning';
$permissionResults = [];
$permissionTestAt = (string)($configRow['bot_permissions_test_at'] ?? '');
if (!empty($configRow['bot_permissions_test_json'])) {
  try {
    $decoded = Db::jsonDecode((string)$configRow['bot_permissions_test_json'], []);
    if (is_array($decoded)) {
      $permissionResults = $decoded;
    }
  } catch (Throwable $e) {
    $permissionResults = [];
  }
}

if ($permissionResults !== [] && isset($permissionResults['overall_ok'])) {
  $permissionTestTone = !empty($permissionResults['overall_ok']) ? 'success' : 'danger';
  $permissionTest = 'Permission test ' . (!empty($permissionResults['overall_ok']) ? 'passed' : 'failed');
  if ($permissionTestAt !== '') {
    $permissionTest .= ' at ' . $permissionTestAt;
  }
  $permissionTest .= '.';
} elseif ($permissionTestRow) {
  $permissionStatus = (string)($permissionTestRow['status'] ?? '');
  $permissionAt = (string)($permissionTestRow['sent_at'] ?? $permissionTestRow['created_at'] ?? '');
  if ($permissionStatus === 'sent') {
    $permissionTestTone = 'success';
    $permissionTest = 'Permission test passed' . ($permissionAt !== '' ? ' at ' . $permissionAt : '') . '.';
  } elseif ($permissionStatus === 'failed') {
    $permissionTestTone = 'danger';
    $permissionError = (string)($permissionTestRow['last_error'] ?? 'Permission test failed.');
    $permissionTest = 'Permission test failed: ' . $permissionError;
  } elseif ($permissionStatus === 'sending') {
    $permissionTest = 'Permission test in progress' . ($permissionAt !== '' ? ' since ' . $permissionAt : '') . '.';
  } else {
    $permissionTest = 'Permission test queued' . ($permissionAt !== '' ? ' at ' . $permissionAt : '') . '.';
  }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require __DIR__ . '/../../../../src/Views/partials/admin/discord_status.php';
