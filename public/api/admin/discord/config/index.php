<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../_helpers.php';
require_once __DIR__ . '/../../../bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'webhook.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);
$data = api_read_json();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

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
    'auto_thread_create_on_request' => 1,
    'auto_archive_on_complete' => 1,
    'auto_lock_on_complete' => 1,
    'role_map_json' => null,
    'last_bot_action_at' => null,
  ];
};

if ($method === 'POST') {
  $action = (string)($data['action'] ?? '');

  if ($action === 'register_commands' && !empty($services['discord_events'])) {
    $services['discord_events']->enqueueAdminTask($corpId, 'discord.commands.register', []);
    api_send_json(['ok' => true, 'queued' => true]);
  }

  if ($action === 'test_permissions' && !empty($services['discord_events'])) {
    $services['discord_events']->enqueueAdminTask($corpId, 'discord.bot.permissions_test', []);
    api_send_json(['ok' => true, 'queued' => true]);
  }

  $current = $loadConfig($db, $corpId);
  $enabledWebhooks = array_key_exists('enabled_webhooks', $data)
    ? (!empty($data['enabled_webhooks']) ? 1 : 0)
    : (int)($current['enabled_webhooks'] ?? 1);
  $enabledBot = array_key_exists('enabled_bot', $data)
    ? (!empty($data['enabled_bot']) ? 1 : 0)
    : (int)($current['enabled_bot'] ?? 0);
  $applicationId = array_key_exists('application_id', $data)
    ? trim((string)($data['application_id'] ?? ''))
    : (string)($current['application_id'] ?? '');
  $publicKey = array_key_exists('public_key', $data)
    ? trim((string)($data['public_key'] ?? ''))
    : (string)($current['public_key'] ?? '');
  $guildId = array_key_exists('guild_id', $data)
    ? trim((string)($data['guild_id'] ?? ''))
    : (string)($current['guild_id'] ?? '');
  $rateLimit = array_key_exists('rate_limit_per_minute', $data)
    ? max(1, (int)($data['rate_limit_per_minute'] ?? 20))
    : (int)($current['rate_limit_per_minute'] ?? 20);
  $dedupeWindow = array_key_exists('dedupe_window_seconds', $data)
    ? max(0, (int)($data['dedupe_window_seconds'] ?? 60))
    : (int)($current['dedupe_window_seconds'] ?? 60);
  $commandsEphemeral = array_key_exists('commands_ephemeral_default', $data)
    ? (!empty($data['commands_ephemeral_default']) ? 1 : 0)
    : (int)($current['commands_ephemeral_default'] ?? 1);
  $channelMode = array_key_exists('channel_mode', $data)
    ? (string)($data['channel_mode'] ?? 'threads')
    : (string)($current['channel_mode'] ?? 'threads');
  if (!in_array($channelMode, ['threads', 'channels'], true)) {
    $channelMode = 'threads';
  }
  $haulingChannelId = array_key_exists('hauling_channel_id', $data)
    ? trim((string)($data['hauling_channel_id'] ?? ''))
    : (string)($current['hauling_channel_id'] ?? '');
  $requesterThreadAccess = array_key_exists('requester_thread_access', $data)
    ? (string)($data['requester_thread_access'] ?? 'read_only')
    : (string)($current['requester_thread_access'] ?? 'read_only');
  if (!in_array($requesterThreadAccess, ['none', 'read_only', 'full'], true)) {
    $requesterThreadAccess = 'read_only';
  }
  $autoThreadCreate = array_key_exists('auto_thread_create_on_request', $data)
    ? (!empty($data['auto_thread_create_on_request']) ? 1 : 0)
    : (int)($current['auto_thread_create_on_request'] ?? 1);
  $autoArchive = array_key_exists('auto_archive_on_complete', $data)
    ? (!empty($data['auto_archive_on_complete']) ? 1 : 0)
    : (int)($current['auto_archive_on_complete'] ?? 1);
  $autoLock = array_key_exists('auto_lock_on_complete', $data)
    ? (!empty($data['auto_lock_on_complete']) ? 1 : 0)
    : (int)($current['auto_lock_on_complete'] ?? 1);
  $roleMapJson = array_key_exists('role_map_json', $data)
    ? ($data['role_map_json'] ?? null)
    : ($current['role_map_json'] ?? null);
  if (is_array($roleMapJson)) {
    $roleMapJson = Db::jsonEncode($roleMapJson);
  }

  $before = $db->one("SELECT * FROM discord_config WHERE corp_id = :cid LIMIT 1", ['cid' => $corpId]);
  $db->execute(
    "INSERT INTO discord_config
      (corp_id, enabled_webhooks, enabled_bot, application_id, public_key, guild_id, rate_limit_per_minute, dedupe_window_seconds,
       commands_ephemeral_default, channel_mode, hauling_channel_id, requester_thread_access, auto_thread_create_on_request,
       auto_archive_on_complete, auto_lock_on_complete, role_map_json, bot_token_configured)
     VALUES
      (:cid, :enabled_webhooks, :enabled_bot, :application_id, :public_key, :guild_id, :rate_limit, :dedupe_window,
       :commands_ephemeral, :channel_mode, :hauling_channel_id, :requester_thread_access, :auto_thread_create_on_request,
       :auto_archive_on_complete, :auto_lock_on_complete, :role_map_json, :bot_token_configured)
     ON DUPLICATE KEY UPDATE
      enabled_webhooks = VALUES(enabled_webhooks),
      enabled_bot = VALUES(enabled_bot),
      application_id = VALUES(application_id),
      public_key = VALUES(public_key),
      guild_id = VALUES(guild_id),
      rate_limit_per_minute = VALUES(rate_limit_per_minute),
      dedupe_window_seconds = VALUES(dedupe_window_seconds),
      commands_ephemeral_default = VALUES(commands_ephemeral_default),
      channel_mode = VALUES(channel_mode),
      hauling_channel_id = VALUES(hauling_channel_id),
      requester_thread_access = VALUES(requester_thread_access),
      auto_thread_create_on_request = VALUES(auto_thread_create_on_request),
      auto_archive_on_complete = VALUES(auto_archive_on_complete),
      auto_lock_on_complete = VALUES(auto_lock_on_complete),
      role_map_json = VALUES(role_map_json),
      bot_token_configured = VALUES(bot_token_configured),
      updated_at = UTC_TIMESTAMP()",
    [
      'cid' => $corpId,
      'enabled_webhooks' => $enabledWebhooks,
      'enabled_bot' => $enabledBot,
      'application_id' => $applicationId !== '' ? $applicationId : null,
      'public_key' => $publicKey !== '' ? $publicKey : null,
      'guild_id' => $guildId !== '' ? $guildId : null,
      'rate_limit' => $rateLimit,
      'dedupe_window' => $dedupeWindow,
      'commands_ephemeral' => $commandsEphemeral,
      'channel_mode' => $channelMode,
      'hauling_channel_id' => $haulingChannelId !== '' ? $haulingChannelId : null,
      'requester_thread_access' => $requesterThreadAccess,
      'auto_thread_create_on_request' => $autoThreadCreate,
      'auto_archive_on_complete' => $autoArchive,
      'auto_lock_on_complete' => $autoLock,
      'role_map_json' => $roleMapJson,
      'bot_token_configured' => !empty($config['discord']['bot_token']) ? 1 : 0,
    ]
  );

  $after = $loadConfig($db, $corpId);
  $db->audit(
    $corpId,
    $authCtx['user_id'],
    $authCtx['character_id'],
    'discord.config.update',
    'discord_config',
    (string)$corpId,
    $before,
    $after,
    $_SERVER['REMOTE_ADDR'] ?? null,
    $_SERVER['HTTP_USER_AGENT'] ?? null
  );

  api_send_json(['ok' => true, 'config' => $after]);
}

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
$configRow = $loadConfig($db, $corpId);
$roleMapCount = 0;
if (!empty($configRow['role_map_json'])) {
  $decoded = Db::jsonDecode((string)$configRow['role_map_json'], []);
  if (is_array($decoded)) {
    $roleMapCount = count(array_filter($decoded, static fn($value) => is_string($value) && trim($value) !== ''));
  }
}

api_send_json([
  'ok' => true,
  'config' => $configRow,
  'status' => [
    'bot_token_configured' => !empty($config['discord']['bot_token']),
    'public_key_configured' => !empty($config['discord']['public_key']) || !empty($configRow['public_key']),
    'guild_id_set' => !empty($configRow['guild_id']),
    'channel_mode' => $configRow['channel_mode'] ?? 'threads',
    'hauling_channel_id' => $configRow['hauling_channel_id'] ?? null,
    'role_mapping_count' => $roleMapCount,
    'last_bot_action_at' => $configRow['last_bot_action_at'] ?? null,
    'pending_outbox' => $pendingCount,
    'last_success_at' => $lastSent,
    'last_error' => $lastError,
  ],
]);
