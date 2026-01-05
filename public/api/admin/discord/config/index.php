<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../_helpers.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

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
    'application_id' => '',
    'guild_id' => '',
    'rate_limit_per_minute' => 20,
    'dedupe_window_seconds' => 60,
    'commands_ephemeral_default' => 1,
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

  $db->execute(
    "INSERT INTO discord_config
      (corp_id, enabled_webhooks, enabled_bot, application_id, guild_id, rate_limit_per_minute, dedupe_window_seconds, commands_ephemeral_default)
     VALUES
      (:cid, :enabled_webhooks, :enabled_bot, :application_id, :guild_id, :rate_limit, :dedupe_window, :commands_ephemeral)
     ON DUPLICATE KEY UPDATE
      enabled_webhooks = VALUES(enabled_webhooks),
      enabled_bot = VALUES(enabled_bot),
      application_id = VALUES(application_id),
      guild_id = VALUES(guild_id),
      rate_limit_per_minute = VALUES(rate_limit_per_minute),
      dedupe_window_seconds = VALUES(dedupe_window_seconds),
      commands_ephemeral_default = VALUES(commands_ephemeral_default),
      updated_at = UTC_TIMESTAMP()",
    [
      'cid' => $corpId,
      'enabled_webhooks' => $enabledWebhooks,
      'enabled_bot' => $enabledBot,
      'application_id' => $applicationId !== '' ? $applicationId : null,
      'guild_id' => $guildId !== '' ? $guildId : null,
      'rate_limit' => $rateLimit,
      'dedupe_window' => $dedupeWindow,
      'commands_ephemeral' => $commandsEphemeral,
    ]
  );

  api_send_json(['ok' => true, 'config' => $loadConfig($db, $corpId)]);
}

$webhookCount = (int)($db->fetchValue(
  "SELECT COUNT(*) FROM discord_channel_map WHERE corp_id = :cid AND mode = 'webhook' AND webhook_url IS NOT NULL",
  ['cid' => $corpId]
) ?? 0);
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

api_send_json([
  'ok' => true,
  'config' => $loadConfig($db, $corpId),
  'status' => [
    'bot_token_configured' => !empty($config['discord']['bot_token']),
    'public_key_configured' => !empty($config['discord']['public_key']),
    'webhook_count' => $webhookCount,
    'pending_outbox' => $pendingCount,
    'last_success_at' => $lastSent,
    'last_error' => $lastError,
  ],
]);
