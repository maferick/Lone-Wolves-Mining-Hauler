<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'webhook.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Discord';

$msg = null;
$msgTone = 'info';
$errors = [];

$portalRights = [
  ['key' => 'hauling.member', 'label' => 'Hauling member', 'description' => 'Base portal access for hauling participants.'],
  ['key' => 'hauling.hauler', 'label' => 'Hauling hauler', 'description' => 'Ops visibility and hauler execution tools.'],
];

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

$isSnowflake = static function (?string $value): bool {
  if ($value === null) {
    return false;
  }
  $value = trim($value);
  return $value !== '' && ctype_digit($value) && strlen($value) >= 17;
};

$saveConfig = static function (Db $db, int $corpId, array $updates, array $authCtx) use ($loadConfig): void {
  $before = $db->one("SELECT * FROM discord_config WHERE corp_id = :cid LIMIT 1", ['cid' => $corpId]);
  $current = $before ?: $loadConfig($db, $corpId);

  $merged = array_merge($current, $updates);
  $merged['bot_token_configured'] = !empty($GLOBALS['config']['discord']['bot_token']) ? 1 : 0;

  $db->execute(
    "INSERT INTO discord_config
      (corp_id, enabled_webhooks, enabled_bot, application_id, public_key, guild_id, rate_limit_per_minute,
       dedupe_window_seconds, commands_ephemeral_default, channel_mode, hauling_channel_id, requester_thread_access,
       auto_thread_create_on_request, auto_archive_on_complete, auto_lock_on_complete, role_map_json, bot_token_configured)
     VALUES
      (:cid, :enabled_webhooks, :enabled_bot, :application_id, :public_key, :guild_id, :rate_limit, :dedupe_window,
       :commands_ephemeral, :channel_mode, :hauling_channel_id, :requester_thread_access,
       :auto_thread_create_on_request, :auto_archive_on_complete, :auto_lock_on_complete, :role_map_json, :bot_token_configured)
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
      'enabled_webhooks' => (int)($merged['enabled_webhooks'] ?? 1),
      'enabled_bot' => (int)($merged['enabled_bot'] ?? 0),
      'application_id' => $merged['application_id'] !== '' ? $merged['application_id'] : null,
      'public_key' => $merged['public_key'] !== '' ? $merged['public_key'] : null,
      'guild_id' => $merged['guild_id'] !== '' ? $merged['guild_id'] : null,
      'rate_limit' => (int)($merged['rate_limit_per_minute'] ?? 20),
      'dedupe_window' => (int)($merged['dedupe_window_seconds'] ?? 60),
      'commands_ephemeral' => (int)($merged['commands_ephemeral_default'] ?? 1),
      'channel_mode' => $merged['channel_mode'] ?? 'threads',
      'hauling_channel_id' => $merged['hauling_channel_id'] !== '' ? $merged['hauling_channel_id'] : null,
      'requester_thread_access' => $merged['requester_thread_access'] ?? 'read_only',
      'auto_thread_create_on_request' => (int)($merged['auto_thread_create_on_request'] ?? 1),
      'auto_archive_on_complete' => (int)($merged['auto_archive_on_complete'] ?? 1),
      'auto_lock_on_complete' => (int)($merged['auto_lock_on_complete'] ?? 1),
      'role_map_json' => $merged['role_map_json'],
      'bot_token_configured' => (int)($merged['bot_token_configured'] ?? 0),
    ]
  );

  $after = $db->one("SELECT * FROM discord_config WHERE corp_id = :cid LIMIT 1", ['cid' => $corpId]);
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
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  $configRow = $loadConfig($db, $corpId);

  if ($action === 'save_bot_settings') {
    $applicationId = trim((string)($_POST['application_id'] ?? ''));
    $publicKey = trim((string)($_POST['public_key'] ?? ''));
    $guildId = trim((string)($_POST['guild_id'] ?? ''));

    if ($applicationId !== '' && !$isSnowflake($applicationId)) {
      $errors[] = 'Application ID must be a numeric snowflake.';
    }
    if ($guildId !== '' && !$isSnowflake($guildId)) {
      $errors[] = 'Guild ID must be a numeric snowflake.';
    }

    if ($errors === []) {
      $saveConfig($db, $corpId, [
        'application_id' => $applicationId,
        'public_key' => $publicKey,
        'guild_id' => $guildId,
      ], $authCtx);
      $msg = 'Bot settings saved.';
    }
  } elseif ($action === 'save_role_mapping') {
    $roleMap = $_POST['role_map'] ?? [];
    $normalized = [];
    if (is_array($roleMap)) {
      foreach ($roleMap as $key => $value) {
        $roleId = trim((string)$value);
        if ($roleId === '') {
          continue;
        }
        if (!$isSnowflake($roleId)) {
          $errors[] = 'Role IDs must be numeric Discord snowflakes.';
          break;
        }
        $normalized[(string)$key] = $roleId;
      }
    }

    if ($errors === []) {
      $saveConfig($db, $corpId, [
        'role_map_json' => $normalized !== [] ? Db::jsonEncode($normalized) : null,
      ], $authCtx);
      $msg = 'Role mapping saved.';
    }
  } elseif ($action === 'save_channel_topology') {
    $channelMode = (string)($_POST['channel_mode'] ?? 'threads');
    if (!in_array($channelMode, ['threads', 'channels'], true)) {
      $channelMode = 'threads';
    }
    $haulingChannelId = trim((string)($_POST['hauling_channel_id'] ?? ''));
    $requesterThreadAccess = (string)($_POST['requester_thread_access'] ?? 'read_only');
    if (!in_array($requesterThreadAccess, ['none', 'read_only', 'full'], true)) {
      $requesterThreadAccess = 'read_only';
    }

    if ($haulingChannelId !== '' && !$isSnowflake($haulingChannelId)) {
      $errors[] = 'Hauling channel ID must be a numeric snowflake.';
    }

    if ($errors === []) {
      $saveConfig($db, $corpId, [
        'channel_mode' => $channelMode,
        'hauling_channel_id' => $haulingChannelId,
        'requester_thread_access' => $requesterThreadAccess,
        'auto_thread_create_on_request' => !empty($_POST['auto_thread_create_on_request']) ? 1 : 0,
        'auto_archive_on_complete' => !empty($_POST['auto_archive_on_complete']) ? 1 : 0,
        'auto_lock_on_complete' => !empty($_POST['auto_lock_on_complete']) ? 1 : 0,
      ], $authCtx);
      $msg = 'Channel topology saved.';
    }
  } elseif ($action === 'register_commands') {
    if (!empty($services['discord_events'])) {
      $services['discord_events']->enqueueAdminTask($corpId, 'discord.commands.register', []);
      $msg = 'Command registration queued.';
    }
  } elseif ($action === 'test_permissions') {
    if (!empty($services['discord_events'])) {
      $services['discord_events']->enqueueAdminTask($corpId, 'discord.bot.permissions_test', []);
      $msg = 'Permission test queued.';
    }
  } elseif ($action === 'send_test_message') {
    if (!empty($services['discord_events'])) {
      $services['discord_events']->enqueueBotTestMessage($corpId);
      $msg = 'Test message queued.';
    }
  } elseif ($action === 'test_role_sync') {
    if (!empty($services['discord_events'])) {
      $link = $db->one(
        "SELECT discord_user_id FROM discord_user_link WHERE user_id = :uid LIMIT 1",
        ['uid' => (int)($authCtx['user_id'] ?? 0)]
      );
      if ($link) {
        $services['discord_events']->enqueueRoleSyncUser($corpId, (int)$authCtx['user_id'], (string)$link['discord_user_id']);
        $msg = 'Role sync queued for your linked account.';
      } else {
        $errors[] = 'No linked Discord account found for this user.';
      }
    }
  } elseif ($action === 'sync_roles_all') {
    if (!empty($services['discord_events'])) {
      $queued = $services['discord_events']->enqueueRoleSyncAll($corpId);
      $msg = 'Queued role sync for ' . $queued . ' linked users.';
    }
  } elseif ($action === 'test_interaction') {
    $baseUrl = rtrim((string)($config['app']['base_url'] ?? ''), '/');
    if ($baseUrl === '') {
      $errors[] = 'Base URL is not configured (APP_BASE_URL).';
    } else {
      $basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
      $url = $baseUrl . ($basePath ?: '') . '/api/discord/interactions/health';
      $result = @file_get_contents($url);
      if ($result === false) {
        $errors[] = 'Interaction endpoint check failed.';
      } else {
        $msg = 'Interaction endpoint responded successfully.';
      }
    }
  }

  if ($errors !== []) {
    $msg = implode(' ', $errors);
    $msgTone = 'error';
  }
}

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

$botTokenConfigured = !empty($config['discord']['bot_token']);
$publicKeyConfigured = !empty($configRow['public_key']) || !empty($config['discord']['public_key']);
$roleMappingCount = count(array_filter($roleMap, static fn($value) => is_string($value) && trim($value) !== ''));

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="card">
  <div class="card-header">
    <h2>Discord Integration</h2>
    <p class="muted">Configure bot access, rights-based roles, and hauling channel topology.</p>
  </div>

  <div class="content">
    <?php if ($msg): ?><div class="pill <?= $msgTone === 'error' ? 'pill-danger' : '' ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <section style="margin-bottom:18px;">
      <h3>Bot / OAuth Settings</h3>
      <div class="card" style="padding:12px;">
        <form method="post">
          <input type="hidden" name="action" value="save_bot_settings" />
          <div class="row">
            <div>
              <div class="label">Discord Application ID</div>
              <input class="input" name="application_id" value="<?= htmlspecialchars((string)($configRow['application_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div>
              <div class="label">Discord Public Key</div>
              <input class="input" name="public_key" value="<?= htmlspecialchars((string)($configRow['public_key'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div>
              <div class="label">Guild ID (optional)</div>
              <input class="input" name="guild_id" value="<?= htmlspecialchars((string)($configRow['guild_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
            </div>
          </div>
          <div class="muted" style="margin-top:10px;">Bot token configured: <?= $botTokenConfigured ? 'yes' : 'no' ?></div>
          <div style="margin-top:12px;">
            <button class="btn" type="submit">Save Bot Settings</button>
          </div>
        </form>
        <div class="row" style="margin-top:12px; gap:10px;">
          <form method="post">
            <input type="hidden" name="action" value="register_commands" />
            <button class="btn" type="submit">Register/Refresh Slash Commands</button>
          </form>
          <form method="post">
            <input type="hidden" name="action" value="test_permissions" />
            <button class="btn ghost" type="submit">Test Bot Permissions</button>
          </form>
        </div>
      </div>
    </section>

    <section style="margin-bottom:18px;">
      <h3>Rights → Discord Role Mapping</h3>
      <div class="card" style="padding:12px;">
        <form method="post">
          <input type="hidden" name="action" value="save_role_mapping" />
          <table class="table">
            <thead>
              <tr>
                <th>Portal right</th>
                <th>Description</th>
                <th>Discord Role ID</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($portalRights as $right): ?>
                <?php $roleValue = (string)($roleMap[$right['key']] ?? ''); ?>
                <tr>
                  <td>
                    <strong><?= htmlspecialchars($right['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <div class="muted" style="font-size:12px;"><?= htmlspecialchars($right['key'], ENT_QUOTES, 'UTF-8') ?></div>
                  </td>
                  <td><?= htmlspecialchars($right['description'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td>
                    <input class="input" name="role_map[<?= htmlspecialchars($right['key'], ENT_QUOTES, 'UTF-8') ?>]" placeholder="123456789012345678" value="<?= htmlspecialchars($roleValue, ENT_QUOTES, 'UTF-8') ?>" />
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div style="margin-top:10px;">
            <button class="btn" type="submit">Save Role Mapping</button>
          </div>
        </form>
        <div class="row" style="margin-top:12px; gap:10px;">
          <form method="post">
            <input type="hidden" name="action" value="test_role_sync" />
            <button class="btn ghost" type="submit">Test Role Sync (current user)</button>
          </form>
          <form method="post">
            <input type="hidden" name="action" value="sync_roles_all" />
            <button class="btn ghost" type="submit">Sync Roles for Linked Users</button>
          </form>
        </div>
      </div>
    </section>

    <section style="margin-bottom:18px;">
      <h3>Channel Topology</h3>
      <div class="card" style="padding:12px;">
        <form method="post">
          <input type="hidden" name="action" value="save_channel_topology" />
          <div class="label">Delivery mode</div>
          <label style="display:flex; gap:8px; align-items:center; margin-top:6px;">
            <input type="radio" name="channel_mode" value="threads" <?= ($configRow['channel_mode'] ?? 'threads') === 'threads' ? 'checked' : '' ?> />
            <span>Single channel with threads</span>
          </label>
          <label style="display:flex; gap:8px; align-items:center; margin-top:6px;">
            <input type="radio" name="channel_mode" value="channels" <?= ($configRow['channel_mode'] ?? 'threads') === 'channels' ? 'checked' : '' ?> />
            <span>Multiple channels (advanced)</span>
          </label>

          <div class="row" style="margin-top:12px;">
            <div>
              <div class="label">Hauling Channel ID (threads mode)</div>
              <input class="input" name="hauling_channel_id" value="<?= htmlspecialchars((string)($configRow['hauling_channel_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div>
              <div class="label">Requester thread access</div>
              <select class="input" name="requester_thread_access">
                <?php $access = (string)($configRow['requester_thread_access'] ?? 'read_only'); ?>
                <option value="none" <?= $access === 'none' ? 'selected' : '' ?>>None</option>
                <option value="read_only" <?= $access === 'read_only' ? 'selected' : '' ?>>Read-only</option>
                <option value="full" <?= $access === 'full' ? 'selected' : '' ?>>Full</option>
              </select>
            </div>
          </div>

          <div style="margin-top:12px;">
            <label style="display:flex; gap:8px; align-items:center;">
              <input type="checkbox" name="auto_thread_create_on_request" <?= !empty($configRow['auto_thread_create_on_request']) ? 'checked' : '' ?> />
              <span>Auto-create thread on request creation</span>
            </label>
            <label style="display:flex; gap:8px; align-items:center; margin-top:6px;">
              <input type="checkbox" name="auto_archive_on_complete" <?= !empty($configRow['auto_archive_on_complete']) ? 'checked' : '' ?> />
              <span>Auto-archive thread on completion</span>
            </label>
            <label style="display:flex; gap:8px; align-items:center; margin-top:6px;">
              <input type="checkbox" name="auto_lock_on_complete" <?= !empty($configRow['auto_lock_on_complete']) ? 'checked' : '' ?> />
              <span>Auto-lock thread on completion</span>
            </label>
          </div>

          <div style="margin-top:12px;">
            <button class="btn" type="submit">Save Channel Topology</button>
          </div>
        </form>
      </div>
    </section>

    <section style="margin-bottom:18px;">
      <h3>Tests & Status</h3>
      <div class="card" style="padding:12px;">
        <div class="label">Status</div>
        <div class="muted">Bot token configured: <?= $botTokenConfigured ? 'yes' : 'no' ?></div>
        <div class="muted">Public key configured: <?= $publicKeyConfigured ? 'yes' : 'no' ?></div>
        <div class="muted">Guild ID set: <?= !empty($configRow['guild_id']) ? 'yes' : 'no' ?></div>
        <div class="muted">Channel mode: <?= htmlspecialchars((string)($configRow['channel_mode'] ?? 'threads'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="muted">Hauling channel ID: <?= !empty($configRow['hauling_channel_id']) ? htmlspecialchars((string)$configRow['hauling_channel_id'], ENT_QUOTES, 'UTF-8') : '—' ?></div>
        <div class="muted">Role mappings: <?= $roleMappingCount ?></div>
        <div class="muted">Last successful bot action: <?= !empty($configRow['last_bot_action_at']) ? htmlspecialchars((string)$configRow['last_bot_action_at'], ENT_QUOTES, 'UTF-8') : '—' ?></div>
        <div class="muted">Last successful delivery: <?= $lastSent !== '' ? htmlspecialchars($lastSent, ENT_QUOTES, 'UTF-8') : '—' ?></div>
        <div class="muted">Pending outbox: <?= $pendingCount ?></div>
        <div class="muted">Last error: <?= $lastError !== '' ? htmlspecialchars($lastError, ENT_QUOTES, 'UTF-8') : '—' ?></div>

        <div class="row" style="margin-top:12px; gap:10px;">
          <form method="post">
            <input type="hidden" name="action" value="send_test_message" />
            <button class="btn" type="submit">Send Test Bot Message</button>
          </form>
          <form method="post">
            <input type="hidden" name="action" value="test_interaction" />
            <button class="btn ghost" type="submit">Test Interaction Endpoint</button>
          </form>
        </div>
      </div>
    </section>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
?>
