<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;
use App\Services\AuthzService;
use App\Services\DiscordOutboxErrorHelp;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'webhook.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Discord';
$isAdmin = Auth::hasRole($authCtx, 'admin');
$authz = $db ? new AuthzService($db) : null;
$accessConfig = $authz ? $authz->loadAccessConfig() : ['scope' => 'corp', 'alliances' => [], 'corp_id' => null];
$snapshotMaxAge = AuthzService::snapshotMaxAgeSeconds();

$msg = null;
$msgTone = 'info';
$errors = [];

$portalRights = [
  [
    'key' => 'hauling.member',
    'label' => 'Hauling member',
    'description' => 'Base portal access for hauling participants. Leave blank if your Discord server already covers this access.',
  ],
  [
    'key' => 'hauling.hauler',
    'label' => 'Hauling hauler',
    'description' => 'Ops visibility and hauler execution tools. This mapping should always be set.',
  ],
];
$rightsSources = [
  'portal' => 'Portal is authoritative (sync portal → Discord).',
  'discord' => 'Discord is authoritative (sync Discord → portal).',
];
$rightsSourceFields = [
  'hauling.member' => 'rights_source_member',
  'hauling.hauler' => 'rights_source_hauler',
];
$discordEventOptions = [
  'request.created' => 'Request created',
  'request.status_changed' => 'Request status changed',
  'contract.matched' => 'Contract matched',
  'contract.picked_up' => 'Contract picked up',
  'contract.completed' => 'Contract completed',
  'contract.failed' => 'Contract failed',
  'contract.expired' => 'Contract expired',
  'alert.system' => 'System alert',
  'discord.test' => 'Discord test',
];
$discordTabs = [
  ['id' => 'bot-settings', 'label' => 'Bot Settings'],
  ['id' => 'onboarding-dm', 'label' => 'Onboarding DMs'],
  ['id' => 'role-mapping', 'label' => 'Role Mapping'],
  ['id' => 'channel-topology', 'label' => 'Channel Topology'],
  ['id' => 'channel-map', 'label' => 'Channel Map'],
  ['id' => 'templates', 'label' => 'Templates'],
  ['id' => 'outbox', 'label' => 'Outbox'],
  ['id' => 'tests-status', 'label' => 'Tests & Status'],
];
if ($isAdmin) {
  $discordTabs[] = ['id' => 'metaverse', 'label' => 'Metaverse'];
}

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
    'rights_source' => 'portal',
    'rights_source_member' => 'portal',
    'rights_source_hauler' => 'portal',
    'onboarding_dm_enabled' => 1,
    'onboarding_dm_bypass_json' => null,
    'auto_link_username' => 0,
    'last_bot_action_at' => null,
    'bot_permissions_test_json' => null,
    'bot_permissions_test_at' => null,
  ];
};

$isSnowflake = static function (?string $value): bool {
  if ($value === null) {
    return false;
  }
  $value = trim($value);
  return $value !== '' && ctype_digit($value) && strlen($value) >= 17;
};

$renderOutboxErrorHelp = static function (string $errorKey, array $details): string {
  $playbook = DiscordOutboxErrorHelp::resolve($errorKey)
    ?? DiscordOutboxErrorHelp::resolve('discord.unknown_error');
  if (!$playbook) {
    return '';
  }

  $detailParts = [];
  if (!empty($details['http_status'])) {
    $detailParts[] = 'HTTP ' . (int)$details['http_status'];
  }
  if (!empty($details['discord_code'])) {
    $detailParts[] = 'Discord code ' . (int)$details['discord_code'];
  }
  if (!empty($details['message'])) {
    $detailParts[] = (string)$details['message'];
  }
  $detailLine = $detailParts !== [] ? implode(' • ', $detailParts) : 'Discord error details unavailable.';

  ob_start();
  ?>
  <div class="card" style="padding:10px; background:rgba(255,255,255,0.02);">
    <div style="font-weight:600;"><?= htmlspecialchars((string)($playbook['title'] ?? 'Discord error help'), ENT_QUOTES, 'UTF-8') ?></div>
    <div class="muted" style="margin-top:4px;"><?= htmlspecialchars($detailLine, ENT_QUOTES, 'UTF-8') ?></div>
    <div style="margin-top:8px;">
      <div class="label" style="font-size:12px;">What this means</div>
      <div><?= htmlspecialchars((string)($playbook['meaning'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <div style="margin-top:8px;">
      <div class="label" style="font-size:12px;">Most common causes</div>
      <ul style="margin:6px 0 0 18px;">
        <?php foreach (($playbook['causes'] ?? []) as $cause): ?>
          <li><?= htmlspecialchars((string)$cause, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div style="margin-top:8px;">
      <div class="label" style="font-size:12px;">How to fix</div>
      <ul style="margin:6px 0 0 18px;">
        <?php foreach (($playbook['fix'] ?? []) as $fix): ?>
          <li>☐ <?= htmlspecialchars((string)$fix, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php if (!empty($playbook['verify'])): ?>
      <div style="margin-top:8px;">
        <div class="label" style="font-size:12px;">How to verify</div>
        <ul style="margin:6px 0 0 18px;">
          <?php foreach (($playbook['verify'] ?? []) as $verify): ?>
            <li><?= htmlspecialchars((string)$verify, ENT_QUOTES, 'UTF-8') ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
  <?php
  return (string)ob_get_clean();
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
      auto_thread_create_on_request, thread_auto_archive_minutes, auto_archive_on_complete, auto_lock_on_complete, role_map_json, rights_source,
      rights_source_member, rights_source_hauler, onboarding_dm_enabled, onboarding_dm_bypass_json, auto_link_username, bot_token_configured)
     VALUES
      (:cid, :enabled_webhooks, :enabled_bot, :application_id, :public_key, :guild_id, :rate_limit, :dedupe_window,
       :commands_ephemeral, :channel_mode, :hauling_channel_id, :requester_thread_access,
       :auto_thread_create_on_request, :thread_auto_archive_minutes, :auto_archive_on_complete, :auto_lock_on_complete, :role_map_json, :rights_source,
       :rights_source_member, :rights_source_hauler, :onboarding_dm_enabled, :onboarding_dm_bypass_json, :auto_link_username, :bot_token_configured)
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
      thread_auto_archive_minutes = VALUES(thread_auto_archive_minutes),
      auto_archive_on_complete = VALUES(auto_archive_on_complete),
      auto_lock_on_complete = VALUES(auto_lock_on_complete),
      role_map_json = VALUES(role_map_json),
      rights_source = VALUES(rights_source),
      rights_source_member = VALUES(rights_source_member),
      rights_source_hauler = VALUES(rights_source_hauler),
      onboarding_dm_enabled = VALUES(onboarding_dm_enabled),
      onboarding_dm_bypass_json = VALUES(onboarding_dm_bypass_json),
      auto_link_username = VALUES(auto_link_username),
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
      'auto_thread_create_on_request' => (int)($merged['auto_thread_create_on_request'] ?? 0),
      'thread_auto_archive_minutes' => (int)($merged['thread_auto_archive_minutes'] ?? 1440),
      'auto_archive_on_complete' => (int)($merged['auto_archive_on_complete'] ?? 1),
      'auto_lock_on_complete' => (int)($merged['auto_lock_on_complete'] ?? 1),
      'role_map_json' => $merged['role_map_json'],
      'rights_source' => $merged['rights_source'] ?? 'portal',
      'rights_source_member' => $merged['rights_source_member'] ?? 'portal',
      'rights_source_hauler' => $merged['rights_source_hauler'] ?? 'portal',
      'onboarding_dm_enabled' => (int)($merged['onboarding_dm_enabled'] ?? 1),
      'onboarding_dm_bypass_json' => $merged['onboarding_dm_bypass_json'],
      'auto_link_username' => (int)($merged['auto_link_username'] ?? 0),
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
  $allowedEvents = array_keys($discordEventOptions);

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
    $rightsSourceInput = $_POST['rights_source'] ?? [];
    $normalized = [];
    $normalizedRightsSources = [];
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

    foreach ($portalRights as $right) {
      $rightKey = (string)($right['key'] ?? '');
      if ($rightKey === '') {
        continue;
      }
      $legacySource = (string)($configRow['rights_source'] ?? 'portal');
      $storedSource = (string)($configRow[$rightsSourceFields[$rightKey] ?? ''] ?? '');
      $selected = $storedSource !== '' ? $storedSource : $legacySource;
      if (is_array($rightsSourceInput) && isset($rightsSourceInput[$rightKey])) {
        $selected = trim((string)$rightsSourceInput[$rightKey]);
      }
      if (!isset($rightsSources[$selected])) {
        $errors[] = 'Rights source selection is invalid.';
        break;
      }
      $normalizedRightsSources[$rightKey] = $selected;
    }

    if (!isset($normalized['hauling.hauler']) || $normalized['hauling.hauler'] === '') {
      $errors[] = 'Hauling hauler role mapping is required.';
    }

    if ($errors === []) {
      $saveConfig($db, $corpId, [
        'role_map_json' => $normalized !== [] ? Db::jsonEncode($normalized) : null,
        'rights_source' => $normalizedRightsSources['hauling.hauler'] ?? 'portal',
        'rights_source_member' => $normalizedRightsSources['hauling.member'] ?? 'portal',
        'rights_source_hauler' => $normalizedRightsSources['hauling.hauler'] ?? 'portal',
      ], $authCtx);
      $msg = 'Role mapping saved.';
    }
  } elseif ($action === 'save_channel_topology') {
    $channelMode = !empty($_POST['use_threads']) ? 'threads' : 'channels';
    $haulingChannelId = trim((string)($_POST['hauling_channel_id'] ?? ''));

    if ($haulingChannelId !== '' && !$isSnowflake($haulingChannelId)) {
      $errors[] = 'Hauling channel ID must be a numeric snowflake.';
    }

    if ($errors === []) {
      $saveConfig($db, $corpId, [
        'channel_mode' => $channelMode,
        'hauling_channel_id' => $haulingChannelId,
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
      if ($queued > 0) {
        $msg = 'Queued role sync for ' . $queued . ' linked users.';
      } else {
        $services['discord_events']->enqueueAdminTask($corpId, 'discord.members.onboard', []);
        $msg = 'No linked users found. Queued onboarding DMs for Discord members.';
      }
    }
  } elseif ($action === 'run_onboarding_scan') {
    if (!empty($services['discord_events'])) {
      $services['discord_events']->enqueueAdminTask($corpId, 'discord.members.onboard', []);
      $msg = 'Onboarding scan queued.';
    } else {
      $errors[] = 'Discord events service unavailable.';
    }
  } elseif ($action === 'run_onboarding_scan_dry') {
    if (!empty($services['discord_events'])) {
      $services['discord_events']->enqueueAdminTask($corpId, 'discord.members.onboard', [
        'dry_run' => true,
      ]);
      $msg = 'Onboarding scan dry run queued.';
    } else {
      $errors[] = 'Discord events service unavailable.';
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
  } elseif ($action === 'toggle_onboarding_dm') {
    $isEnabled = !empty($configRow['onboarding_dm_enabled']);
    $saveConfig($db, $corpId, [
      'onboarding_dm_enabled' => $isEnabled ? 0 : 1,
    ], $authCtx);
    $msg = $isEnabled ? 'Onboarding DMs disabled.' : 'Onboarding DMs enabled.';
  } elseif ($action === 'toggle_auto_link_username') {
    $isEnabled = !empty($configRow['auto_link_username']);
    $saveConfig($db, $corpId, [
      'auto_link_username' => $isEnabled ? 0 : 1,
    ], $authCtx);
    $msg = $isEnabled ? 'Auto-link by username disabled.' : 'Auto-link by username enabled.';
  } elseif ($action === 'save_onboarding_bypass') {
    $rawBypass = trim((string)($_POST['onboarding_dm_bypass'] ?? ''));
    $tokens = $rawBypass === '' ? [] : preg_split('/[\s,]+/', $rawBypass);
    $bypassIds = [];
    $invalidIds = [];
    foreach ($tokens as $token) {
      $token = trim((string)$token);
      if ($token === '') {
        continue;
      }
      if (!$isSnowflake($token)) {
        $invalidIds[] = $token;
        continue;
      }
      $bypassIds[] = $token;
    }
    $bypassIds = array_values(array_unique($bypassIds));
    if ($invalidIds !== []) {
      $errors[] = 'Bypass IDs must be numeric snowflakes. Invalid entries: ' . implode(', ', $invalidIds) . '.';
    }
    if ($errors === []) {
      $saveConfig($db, $corpId, [
        'onboarding_dm_bypass_json' => $bypassIds !== [] ? Db::jsonEncode($bypassIds) : null,
      ], $authCtx);
      $msg = 'Onboarding DM bypass list saved.';
    }
  } elseif ($action === 'remove_onboarding_dm') {
    $outboxId = (int)($_POST['outbox_id'] ?? 0);
    if ($outboxId <= 0) {
      $errors[] = 'Outbox entry id is missing.';
    }
    if ($errors === []) {
      $cleared = $db->execute(
        "DELETE FROM discord_outbox
          WHERE corp_id = :cid
            AND event_key = 'discord.onboarding.dm'
            AND outbox_id = :oid",
        ['cid' => $corpId, 'oid' => $outboxId]
      );
      $msg = $cleared > 0 ? 'Onboarding DM entry removed.' : 'Onboarding DM entry not found.';
    }
  } elseif ($action === 'remove_onboarding_dm_all') {
    $cleared = $db->execute(
      "DELETE FROM discord_outbox
        WHERE corp_id = :cid
          AND event_key = 'discord.onboarding.dm'
          AND status IN ('queued','failed','sending')",
      ['cid' => $corpId]
    );
    $msg = 'Removed ' . $cleared . ' pending onboarding DM entr' . ($cleared === 1 ? 'y' : 'ies') . '.';
  } elseif ($action === 'send_template_tests') {
    if (!empty($services['discord_events'])) {
      if (empty($configRow['hauling_channel_id'])) {
        $errors[] = 'Hauling channel ID is required to send bot template tests.';
      }
      if ($errors === []) {
        $queued = 0;
        foreach ($allowedEvents as $eventKey) {
          $queued += $services['discord_events']->enqueueTemplateTest($corpId, $eventKey, [
            'delivery' => 'bot',
          ]);
        }
        $msg = 'Queued ' . $queued . ' template test messages.';
      }
    }
  } elseif ($action === 'test_thread') {
    if (!empty($services['discord_events'])) {
      $duration = (int)($_POST['thread_duration'] ?? 1);
      if (!in_array($duration, [1, 2], true)) {
        $duration = 1;
      }
      if (($configRow['channel_mode'] ?? 'threads') !== 'threads') {
        $errors[] = 'Channel mode must be set to threads to run a test thread.';
      }
      if (empty($configRow['hauling_channel_id'])) {
        $errors[] = 'Hauling channel ID is required to run a test thread.';
      }
      if ($errors === []) {
        $services['discord_events']->enqueueThreadTest($corpId, $duration);
        $msg = 'Test thread queued. It will auto-close after ' . $duration . ' minute' . ($duration === 1 ? '' : 's') . '.';
      }
    }
  } elseif ($action === 'test_haul_thread_flow') {
    if (!empty($services['discord_events'])) {
      $request = $db->one(
        "SELECT request_id
           FROM haul_request
          WHERE corp_id = :cid
          ORDER BY updated_at DESC, request_id DESC
          LIMIT 1",
        ['cid' => $corpId]
      );
      if (!$request) {
        $errors[] = 'No hauling requests found. Create a request first.';
      } else {
        $requestId = (int)$request['request_id'];
        $queued = 0;
        $queued += $services['discord_events']->enqueueRequestCreated($corpId, $requestId, ['status' => 'requested']);
        $queued += $services['discord_events']->enqueueRequestStatusChanged($corpId, $requestId, 'in_queue', 'requested');
        $queued += $services['discord_events']->enqueueRequestStatusChanged($corpId, $requestId, 'in_transit', 'in_queue');
        $queued += $services['discord_events']->enqueueRequestStatusChanged($corpId, $requestId, 'delivered', 'in_transit');
        $msg = 'Queued haul thread flow test events (' . $queued . ').';
      }
    }
  } elseif ($action === 'save_template') {
    $eventKey = trim((string)($_POST['event_key'] ?? ''));
    if ($eventKey === '' || !in_array($eventKey, $allowedEvents, true)) {
      $errors[] = 'Template event key is invalid.';
    }
    $titleTemplate = (string)($_POST['title_template'] ?? '');
    $bodyTemplate = (string)($_POST['body_template'] ?? '');
    $footerTemplate = (string)($_POST['footer_template'] ?? '');

    if ($errors === []) {
      if (trim($titleTemplate) === '' && trim($bodyTemplate) === '' && trim($footerTemplate) === '') {
        $db->execute(
          "DELETE FROM discord_template WHERE corp_id = :cid AND event_key = :event_key",
          ['cid' => $corpId, 'event_key' => $eventKey]
        );
        $msg = 'Template reset to defaults.';
      } else {
        $db->execute(
          "INSERT INTO discord_template
            (corp_id, event_key, title_template, body_template, footer_template)
           VALUES
            (:cid, :event_key, :title_template, :body_template, :footer_template)
           ON DUPLICATE KEY UPDATE
            title_template = VALUES(title_template),
            body_template = VALUES(body_template),
            footer_template = VALUES(footer_template),
            updated_at = UTC_TIMESTAMP()",
          [
            'cid' => $corpId,
            'event_key' => $eventKey,
            'title_template' => $titleTemplate,
            'body_template' => $bodyTemplate,
            'footer_template' => $footerTemplate,
          ]
        );
        $msg = 'Template saved.';
      }
    }
  } elseif ($action === 'reset_template') {
    $eventKey = trim((string)($_POST['event_key'] ?? ''));
    if ($eventKey === '' || !in_array($eventKey, $allowedEvents, true)) {
      $errors[] = 'Template event key is invalid.';
    }
    if ($errors === []) {
      $db->execute(
        "DELETE FROM discord_template WHERE corp_id = :cid AND event_key = :event_key",
        ['cid' => $corpId, 'event_key' => $eventKey]
      );
      $msg = 'Template reset to defaults.';
    }
  } elseif ($action === 'save_channel_map') {
    $mapId = (int)($_POST['channel_map_id'] ?? 0);
    $eventKey = trim((string)($_POST['event_key'] ?? ''));
    $mode = 'bot';
    $channelId = trim((string)($_POST['channel_id'] ?? ''));
    $isEnabled = !empty($_POST['is_enabled']) ? 1 : 0;

    if ($eventKey === '' || !in_array($eventKey, $allowedEvents, true)) {
      $errors[] = 'Channel map event key is invalid.';
    }
    if ($channelId === '') {
      $errors[] = 'Channel ID is required for bot delivery.';
    } elseif (!$isSnowflake($channelId)) {
      $errors[] = 'Channel ID must be a numeric snowflake.';
    }

    if ($errors === []) {
      if ($mapId > 0) {
        $db->execute(
          "UPDATE discord_channel_map
              SET event_key = :event_key,
                  mode = :mode,
                  channel_id = :channel_id,
                  is_enabled = :is_enabled,
                  updated_at = UTC_TIMESTAMP()
            WHERE channel_map_id = :id AND corp_id = :cid",
          [
            'event_key' => $eventKey,
            'mode' => $mode,
            'channel_id' => $channelId !== '' ? $channelId : null,
            'is_enabled' => $isEnabled,
            'id' => $mapId,
            'cid' => $corpId,
          ]
        );
        $msg = 'Channel mapping updated.';
      } else {
        $db->insert('discord_channel_map', [
          'corp_id' => $corpId,
          'event_key' => $eventKey,
          'mode' => $mode,
          'channel_id' => $channelId !== '' ? $channelId : null,
          'is_enabled' => $isEnabled,
        ]);
        $msg = 'Channel mapping added.';
      }
    }
  } elseif ($action === 'delete_channel_map') {
    $mapId = (int)($_POST['channel_map_id'] ?? 0);
    if ($mapId <= 0) {
      $errors[] = 'Channel map id is missing.';
    }
    if ($errors === []) {
      $db->execute(
        "DELETE FROM discord_channel_map WHERE channel_map_id = :id AND corp_id = :cid",
        ['id' => $mapId, 'cid' => $corpId]
      );
      $msg = 'Channel mapping deleted.';
    }
  } elseif ($action === 'clear_outbox') {
    $cleared = $db->execute(
      "DELETE FROM discord_outbox WHERE corp_id = :cid AND status IN ('queued','failed','sending')",
      ['cid' => $corpId]
    );
    $msg = 'Cleared ' . $cleared . ' pending outbox entr' . ($cleared === 1 ? 'y' : 'ies') . '.';
  }

  if ($errors !== []) {
    $msg = implode(' ', $errors);
    $msgTone = 'error';
  }
}

$configRow = $loadConfig($db, $corpId);
$legacyRightsSource = (string)($configRow['rights_source'] ?? 'portal');
if (empty($configRow['rights_source_member'])) {
  $configRow['rights_source_member'] = $legacyRightsSource;
}
if (empty($configRow['rights_source_hauler'])) {
  $configRow['rights_source_hauler'] = $legacyRightsSource;
}
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
$rightsSourceByRight = [
  'hauling.member' => (string)($configRow['rights_source_member'] ?? $legacyRightsSource),
  'hauling.hauler' => (string)($configRow['rights_source_hauler'] ?? $legacyRightsSource),
];

$pendingCount = (int)($db->fetchValue(
  "SELECT COUNT(*) FROM discord_outbox WHERE corp_id = :cid AND status IN ('queued','failed','sending')",
  ['cid' => $corpId]
) ?? 0);
$outboxRows = $db->select(
  "SELECT outbox_id, event_key, status, attempts, next_attempt_at, last_error, created_at, sent_at, payload_json
     FROM discord_outbox
    WHERE corp_id = :cid
    ORDER BY outbox_id DESC
    LIMIT 50",
  ['cid' => $corpId]
);
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
$templateRows = $db->select(
  "SELECT event_key, title_template, body_template, footer_template
     FROM discord_template
    WHERE corp_id = :cid",
  ['cid' => $corpId]
);
$templatesByKey = [];
foreach ($templateRows as $row) {
  $templatesByKey[(string)$row['event_key']] = [
    'title' => (string)($row['title_template'] ?? ''),
    'body' => (string)($row['body_template'] ?? ''),
    'footer' => (string)($row['footer_template'] ?? ''),
  ];
}
$templateDefaults = [];
if (!empty($services['discord_renderer'])) {
  $templateDefaults = $services['discord_renderer']->defaultTemplates(array_keys($discordEventOptions));
}
$channelMaps = $db->select(
  "SELECT channel_map_id, event_key, mode, channel_id, is_enabled
     FROM discord_channel_map
    WHERE corp_id = :cid
      AND mode = 'bot'
    ORDER BY event_key ASC, channel_map_id ASC",
  ['cid' => $corpId]
);

$botTokenConfigured = !empty($config['discord']['bot_token']);
$publicKeyConfigured = !empty($configRow['public_key']) || !empty($config['discord']['public_key']);
$roleMappingCount = count(array_filter($roleMap, static fn($value) => is_string($value) && trim($value) !== ''));
$onboardingDmEnabled = !empty($configRow['onboarding_dm_enabled']);
$autoLinkUsernameEnabled = !empty($configRow['auto_link_username']);
$onboardingBypassList = [];
if (!empty($configRow['onboarding_dm_bypass_json'])) {
  try {
    $decoded = Db::jsonDecode((string)$configRow['onboarding_dm_bypass_json'], []);
    if (is_array($decoded)) {
      $onboardingBypassList = array_values(array_filter($decoded, 'is_string'));
    }
  } catch (Throwable $e) {
    $onboardingBypassList = [];
  }
}
$outboxEventLabels = array_merge($discordEventOptions, [
  'discord.bot.permissions_test' => 'Bot permissions test',
  'discord.commands.register' => 'Register slash commands',
  'discord.bot.test_message' => 'Bot test message',
  'discord.template.test' => 'Template test message',
  'discord.thread.test' => 'Thread test (create)',
  'discord.thread.test_close' => 'Thread test (close)',
  'discord.roles.sync_user' => 'Role sync',
  'discord.members.onboard' => 'Discord onboarding scan',
  'discord.onboarding.dm' => 'Discord onboarding DM',
  'discord.thread.create' => 'Thread create',
  'discord.thread.complete' => 'Thread complete',
]);

$onboardingScanRows = $db->select(
  "SELECT outbox_id, status, attempts, next_attempt_at, created_at
     FROM discord_outbox
    WHERE corp_id = :cid
      AND event_key = 'discord.members.onboard'
      AND status IN ('queued','failed','sending')
    ORDER BY outbox_id DESC
    LIMIT 200",
  ['cid' => $corpId]
);

$onboardingOutboxRows = $db->select(
  "SELECT outbox_id, status, attempts, next_attempt_at, created_at, payload_json,
          JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.discord_user_id')) AS discord_user_id
     FROM discord_outbox
    WHERE corp_id = :cid
      AND event_key = 'discord.onboarding.dm'
      AND status IN ('queued','failed','sending')
    ORDER BY outbox_id DESC
    LIMIT 200",
  ['cid' => $corpId]
);
$onboardingDiscordIds = [];
foreach ($onboardingOutboxRows as $row) {
  $discordUserId = trim((string)($row['discord_user_id'] ?? ''));
  if ($discordUserId !== '') {
    $onboardingDiscordIds[] = $discordUserId;
  }
}
$onboardingDiscordIds = array_values(array_unique($onboardingDiscordIds));
$pendingOnboardingByDiscord = array_fill_keys($onboardingDiscordIds, true);
$linkedUserByDiscord = [];
$linkedUserIds = [];
if ($onboardingDiscordIds !== []) {
  $idPlaceholders = implode(',', array_fill(0, count($onboardingDiscordIds), '?'));
  $linkRows = $db->select(
    "SELECT l.discord_user_id, l.user_id
       FROM discord_user_link l
       JOIN app_user u ON u.user_id = l.user_id
      WHERE u.corp_id = ?
        AND l.discord_user_id IN ($idPlaceholders)",
    array_merge([$corpId], $onboardingDiscordIds)
  );
  foreach ($linkRows as $linkRow) {
    $discordId = trim((string)($linkRow['discord_user_id'] ?? ''));
    $userId = (int)($linkRow['user_id'] ?? 0);
    if ($discordId === '' || $userId <= 0) {
      continue;
    }
    $linkedUserByDiscord[$discordId] = $userId;
    $linkedUserIds[] = $userId;
  }
}
$haulingMemberUsers = [];
if ($linkedUserIds !== []) {
  $userPlaceholders = implode(',', array_fill(0, count($linkedUserIds), '?'));
  $memberRows = $db->select(
    "SELECT DISTINCT ur.user_id
       FROM user_role ur
       JOIN role_permission rp ON rp.role_id = ur.role_id AND rp.allow = 1
       JOIN permission p ON p.perm_id = rp.perm_id
      WHERE p.perm_key = 'hauling.member'
        AND ur.user_id IN ($userPlaceholders)",
    $linkedUserIds
  );
  foreach ($memberRows as $memberRow) {
    $userId = (int)($memberRow['user_id'] ?? 0);
    if ($userId > 0) {
      $haulingMemberUsers[$userId] = true;
    }
  }
}
$onboardingDmRows = [];
$onboardingLinkedIds = [];
$onboardingNonMemberIds = [];
foreach ($onboardingOutboxRows as $row) {
  $discordUserId = trim((string)($row['discord_user_id'] ?? ''));
  $linkedUserId = $discordUserId !== '' ? ($linkedUserByDiscord[$discordUserId] ?? null) : null;
  if ($linkedUserId !== null) {
    if (!isset($haulingMemberUsers[$linkedUserId])) {
      $onboardingNonMemberIds[] = (int)$row['outbox_id'];
    } else {
      $onboardingLinkedIds[] = (int)$row['outbox_id'];
    }
    continue;
  }
  $onboardingDmRows[] = $row;
}
$onboardingCleanupIds = array_values(array_unique(array_merge($onboardingLinkedIds, $onboardingNonMemberIds)));
if ($onboardingCleanupIds !== []) {
  $cleanupPlaceholders = implode(',', array_fill(0, count($onboardingCleanupIds), '?'));
  $db->execute(
    "DELETE FROM discord_outbox
      WHERE corp_id = ?
        AND event_key = 'discord.onboarding.dm'
        AND outbox_id IN ($cleanupPlaceholders)",
    array_merge([$corpId], $onboardingCleanupIds)
  );
}

$portalUsers = [];
if ($isAdmin) {
  $portalUsers = $db->select(
    "SELECT u.user_id, u.display_name, u.email, u.status, u.last_login_at, u.created_at,
            l.discord_user_id, l.discord_username, l.linked_at, l.last_seen_at
       FROM app_user u
       LEFT JOIN discord_user_link l ON l.user_id = u.user_id
      WHERE u.corp_id = :cid
      ORDER BY u.display_name ASC, u.user_id ASC",
    ['cid' => $corpId]
  );
}

$linkedPortalByDiscord = [];
foreach ($portalUsers as $user) {
  $discordUserId = trim((string)($user['discord_user_id'] ?? ''));
  if ($discordUserId === '') {
    continue;
  }
  $linkedPortalByDiscord[$discordUserId] = $user;
}

$discordRoleMembers = [];
$discordRoleStatus = null;
$discordRoleSnapshotAt = null;
$discordHaulerMembers = [];
$discordHaulerStatus = null;
$discordHaulerSnapshotAt = null;
$discordRoleMembersById = [];
$discordHaulerMembersById = [];
if ($isAdmin) {
  $guildId = trim((string)($configRow['guild_id'] ?? $config['discord']['guild_id'] ?? ''));
  $delivery = $services['discord_delivery'] ?? null;
  $fetchRoleSnapshot = static function (string $roleKey, string $roleId) use ($corpId, $delivery, $config, $guildId): array {
    if ($roleId === '') {
      return [
        'members' => [],
        'status' => "Set a {$roleKey} role mapping to pull Discord membership.",
        'scanned_at' => null,
      ];
    }
    if (empty($delivery)) {
      return [
        'members' => [],
        'status' => 'Discord delivery service unavailable.',
        'scanned_at' => null,
      ];
    }
    /** @var \App\Services\DiscordDeliveryService $delivery */
    $snapshotResponse = $delivery->fetchRoleMemberSnapshot($corpId, $roleId);
    if (!empty($snapshotResponse['ok'])) {
      $snapshotMembers = $snapshotResponse['members'] ?? [];
      if (!is_array($snapshotMembers)) {
        $snapshotMembers = [];
      }
      $members = array_values(array_filter(
        $snapshotMembers,
        static fn($member): bool => is_array($member)
          && in_array($roleId, (array)($member['roles'] ?? []), true)
      ));
      return [
        'members' => $members,
        'status' => null,
        'scanned_at' => trim((string)($snapshotResponse['scanned_at'] ?? '')),
      ];
    }
    if (empty($config['discord']['bot_token'])) {
      return [
        'members' => [],
        'status' => 'Configure a bot token to run a full scan and capture Discord members.',
        'scanned_at' => null,
      ];
    }
    if ($guildId === '') {
      return [
        'members' => [],
        'status' => 'Set a Discord guild ID to run a full scan and capture role membership.',
        'scanned_at' => null,
      ];
    }
    return [
      'members' => [],
      'status' => 'No cached Discord members yet. Run a full onboarding scan to create the snapshot.',
      'scanned_at' => null,
    ];
  };

  $haulingRoleId = trim((string)($roleMap['hauling.member'] ?? ''));
  $haulerRoleId = trim((string)($roleMap['hauling.hauler'] ?? ''));

  $memberSnapshot = $fetchRoleSnapshot('hauling.member', $haulingRoleId);
  $discordRoleMembers = $memberSnapshot['members'];
  $discordRoleStatus = $memberSnapshot['status'];
  $discordRoleSnapshotAt = $memberSnapshot['scanned_at'];

  $haulerSnapshot = $fetchRoleSnapshot('hauling.hauler', $haulerRoleId);
  $discordHaulerMembers = $haulerSnapshot['members'];
  $discordHaulerStatus = $haulerSnapshot['status'];
  $discordHaulerSnapshotAt = $haulerSnapshot['scanned_at'];

  foreach ($discordRoleMembers as $member) {
    $discordUserId = trim((string)($member['discord_user_id'] ?? ''));
    if ($discordUserId !== '') {
      $discordRoleMembersById[$discordUserId] = true;
    }
  }
  foreach ($discordHaulerMembers as $member) {
    $discordUserId = trim((string)($member['discord_user_id'] ?? ''));
    if ($discordUserId !== '') {
      $discordHaulerMembersById[$discordUserId] = true;
    }
  }
}

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="card admin-tabs" data-admin-tabs="discord">
  <div class="card-header">
    <h2>Discord Integration</h2>
    <p class="muted">Configure bot access, rights-based roles, and hauling channel topology.</p>
    <nav class="admin-subnav admin-subnav--tabs" data-admin-tabs-nav aria-label="Discord sections">
      <?php foreach ($discordTabs as $tab): ?>
        <a class="nav-link" href="<?= ($basePath ?: '') ?>/admin/discord/#<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>" data-section="<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8') ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>

  <div class="content">
    <?php if ($msg): ?><div class="pill <?= $msgTone === 'error' ? 'pill-danger' : '' ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <section class="admin-section is-active" id="bot-settings" data-section="bot-settings">
      <div class="admin-section__title">Bot / OAuth Settings</div>
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

    <section class="admin-section" id="onboarding-dm" data-section="onboarding-dm">
      <div class="admin-section__title">Onboarding Direct Messages</div>
      <div class="card" style="padding:12px;">
        <div class="row" style="align-items:center; gap:12px;">
          <div>
            <div class="label">Onboarding DM status</div>
            <div class="muted"><?= $onboardingDmEnabled ? 'Enabled' : 'Disabled' ?></div>
          </div>
          <form method="post">
            <input type="hidden" name="action" value="toggle_onboarding_dm" />
            <button class="btn <?= $onboardingDmEnabled ? 'ghost' : '' ?>" type="submit">
              <?= $onboardingDmEnabled ? 'Disable onboarding DMs' : 'Enable onboarding DMs' ?>
            </button>
          </form>
        </div>
        <div class="muted" style="margin-top:10px;">
          When disabled, onboarding DMs will not send unless the Discord user ID is listed in the bypass list below.
        </div>
        <div class="row" style="align-items:center; gap:12px; margin-top:12px;">
          <div>
            <div class="label">Auto-link by username</div>
            <div class="muted"><?= $autoLinkUsernameEnabled ? 'Enabled' : 'Disabled' ?></div>
          </div>
          <form method="post">
            <input type="hidden" name="action" value="toggle_auto_link_username" />
            <button class="btn <?= $autoLinkUsernameEnabled ? 'ghost' : '' ?>" type="submit">
              <?= $autoLinkUsernameEnabled ? 'Disable auto-link' : 'Enable auto-link' ?>
            </button>
          </form>
        </div>
        <div class="muted" style="margin-top:10px;">
          When enabled, Discord members whose display name (server nickname, global name, or username) exactly matches a portal display name will be linked automatically.
        </div>
      </div>
      <div class="card" style="padding:12px; margin-top:12px;">
        <form method="post">
          <input type="hidden" name="action" value="save_onboarding_bypass" />
          <div class="label">Bypass list (Discord user IDs)</div>
          <textarea class="input" name="onboarding_dm_bypass" rows="4" placeholder="123456789012345678&#10;234567890123456789"><?= htmlspecialchars(implode("\n", $onboardingBypassList), ENT_QUOTES, 'UTF-8') ?></textarea>
          <div class="muted" style="margin-top:8px;">Enter one Discord user ID per line (or comma-separated) to allow onboarding DMs even when disabled.</div>
          <div style="margin-top:10px;">
            <button class="btn" type="submit">Save Bypass List</button>
          </div>
        </form>
      </div>
      <div class="card" style="padding:12px; margin-top:12px;">
        <div class="row" style="align-items:center; justify-content:space-between;">
          <div>
            <div class="label">Onboarding scan jobs</div>
            <div class="muted">
              <?= count($onboardingScanRows) ?> scan job<?= count($onboardingScanRows) === 1 ? '' : 's' ?> queued.
              These scans create onboarding DM entries once processed. Dry runs use the latest cached Discord member snapshot.
            </div>
          </div>
          <div class="row" style="gap:8px; align-items:center;">
            <form method="post">
              <input type="hidden" name="action" value="run_onboarding_scan" />
              <button class="btn" type="submit">Run onboarding scan</button>
            </form>
            <form method="post">
              <input type="hidden" name="action" value="run_onboarding_scan_dry" />
              <button class="btn ghost" type="submit">Dry run scan</button>
            </form>
          </div>
        </div>
        <?php if ($onboardingScanRows === []): ?>
          <div class="muted" style="margin-top:12px;">No pending onboarding scans found.</div>
        <?php else: ?>
          <table class="table" style="margin-top:12px;">
            <thead>
              <tr>
                <th>Outbox ID</th>
                <th>Status</th>
                <th>Attempts</th>
                <th>Next attempt</th>
                <th>Queued</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($onboardingScanRows as $row): ?>
                <tr>
                  <td><?= (int)($row['outbox_id'] ?? 0) ?></td>
                  <td><?= htmlspecialchars((string)($row['status'] ?? 'queued'), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= (int)($row['attempts'] ?? 0) ?></td>
                  <td><?= htmlspecialchars((string)($row['next_attempt_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string)($row['created_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
      <div class="card" style="padding:12px; margin-top:12px;">
        <div class="row" style="align-items:center; justify-content:space-between;">
          <div>
            <div class="label">Pending onboarding DMs</div>
            <div class="muted"><?= count($onboardingDmRows) ?> queued candidate<?= count($onboardingDmRows) === 1 ? '' : 's' ?>.</div>
          </div>
          <form method="post">
            <input type="hidden" name="action" value="remove_onboarding_dm_all" />
            <button class="btn ghost" type="submit" <?= $onboardingDmRows === [] ? 'disabled' : '' ?>>Remove all</button>
          </form>
        </div>
        <?php if ($onboardingDmRows === []): ?>
          <div class="muted" style="margin-top:12px;">No pending onboarding DMs found.</div>
        <?php else: ?>
          <table class="table" style="margin-top:12px;">
            <thead>
              <tr>
                <th>Discord User ID</th>
                <th>Status</th>
                <th>Attempts</th>
                <th>Next attempt</th>
                <th>Queued</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($onboardingDmRows as $row): ?>
                <?php $discordUserId = trim((string)($row['discord_user_id'] ?? '')); ?>
                <tr>
                  <td><code><?= htmlspecialchars($discordUserId !== '' ? $discordUserId : 'Unknown', ENT_QUOTES, 'UTF-8') ?></code></td>
                  <td><?= htmlspecialchars((string)($row['status'] ?? 'queued'), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= (int)($row['attempts'] ?? 0) ?></td>
                  <td><?= htmlspecialchars((string)($row['next_attempt_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string)($row['created_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                  <td>
                    <form method="post">
                      <input type="hidden" name="action" value="remove_onboarding_dm" />
                      <input type="hidden" name="outbox_id" value="<?= (int)($row['outbox_id'] ?? 0) ?>" />
                      <button class="btn ghost" type="submit">Remove</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
        <div class="muted" style="margin-top:10px;">
          Linked users (or users missing the hauling.member right) are automatically removed from this list.
        </div>
      </div>
    </section>

    <section class="admin-section" id="role-mapping" data-section="role-mapping">
      <div class="admin-section__title">Rights → Discord Role Mapping</div>
      <div class="card" style="padding:12px;">
        <form method="post">
          <input type="hidden" name="action" value="save_role_mapping" />
          <div class="label">Rights source</div>
          <p class="muted" style="margin:4px 0 10px;">Choose whether each portal right is mastered in the portal or in Discord.</p>
          <table class="table">
            <thead>
              <tr>
                <th>Portal right</th>
                <th>Description</th>
                <th>Rights source</th>
                <th>Discord Role ID</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($portalRights as $right): ?>
                <?php
                  $rightKey = (string)($right['key'] ?? '');
                  $roleValue = (string)($roleMap[$rightKey] ?? '');
                  $selectedSource = (string)($rightsSourceByRight[$rightKey] ?? $legacyRightsSource);
                ?>
                <tr>
                  <td>
                    <strong><?= htmlspecialchars($right['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <div class="muted" style="font-size:12px;"><?= htmlspecialchars($rightKey, ENT_QUOTES, 'UTF-8') ?></div>
                  </td>
                  <td><?= htmlspecialchars($right['description'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td>
                    <select class="input" name="rights_source[<?= htmlspecialchars($rightKey, ENT_QUOTES, 'UTF-8') ?>]">
                      <?php foreach ($rightsSources as $sourceKey => $sourceLabel): ?>
                        <option value="<?= htmlspecialchars($sourceKey, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedSource === $sourceKey ? 'selected' : '' ?>>
                          <?= htmlspecialchars($sourceLabel, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td>
                    <input class="input" name="role_map[<?= htmlspecialchars($rightKey, ENT_QUOTES, 'UTF-8') ?>]" placeholder="123456789012345678" value="<?= htmlspecialchars($roleValue, ENT_QUOTES, 'UTF-8') ?>" />
                    <?php if ($rightKey === 'hauling.member'): ?>
                      <div class="muted" style="font-size:11px; margin-top:4px;">Optional if your server already grants base hauling access.</div>
                    <?php elseif ($rightKey === 'hauling.hauler'): ?>
                      <div class="muted" style="font-size:11px; margin-top:4px;">Required for hauler execution tools.</div>
                    <?php endif; ?>
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

    <section class="admin-section" id="channel-topology" data-section="channel-topology">
      <div class="admin-section__title">Channel Topology</div>
      <div class="card" style="padding:12px;">
        <form method="post">
          <input type="hidden" name="action" value="save_channel_topology" />
          <div class="label">Haul update routing</div>
          <label style="display:flex; gap:8px; align-items:center; margin-top:6px;">
            <input type="checkbox" name="use_threads" <?= ($configRow['channel_mode'] ?? 'threads') === 'threads' ? 'checked' : '' ?> />
            <span>Use threads for haul lifecycle updates</span>
          </label>

          <div class="row" style="margin-top:12px;">
            <div>
              <div class="label">Base channel ID for haul posts</div>
              <input class="input" name="hauling_channel_id" value="<?= htmlspecialchars((string)($configRow['hauling_channel_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
            </div>
          </div>

          <div style="margin-top:12px;">
            <button class="btn" type="submit">Save Channel Topology</button>
          </div>
        </form>
      </div>
    </section>

    <section class="admin-section" id="channel-map" data-section="channel-map">
      <div class="admin-section__title">Channel Map</div>
      <div class="muted">Route events to specific bot channels.</div>
      <div class="card" style="padding:12px; margin-top:12px;">
        <form method="post">
          <input type="hidden" name="action" value="save_channel_map" />
          <table class="table">
            <thead>
              <tr>
                <th>Event</th>
                <th>Channel ID</th>
                <th>Enabled</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>
                  <select class="input" name="event_key">
                    <?php foreach ($discordEventOptions as $eventKey => $label): ?>
                      <option value="<?= htmlspecialchars($eventKey, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td><input class="input" name="channel_id" placeholder="123456789012345678" /></td>
                <td style="text-align:center;">
                  <input type="checkbox" name="is_enabled" checked />
                </td>
                <td><button class="btn" type="submit">Add</button></td>
              </tr>
            </tbody>
          </table>
        </form>
        <?php if ($channelMaps !== []): ?>
          <table class="table" style="margin-top:18px;">
            <thead>
              <tr>
                <th>Event</th>
                <th>Channel ID</th>
                <th>Enabled</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($channelMaps as $map): ?>
                <tr>
                  <td colspan="4">
                    <form method="post" class="row" style="gap:10px; align-items:center;">
                      <input type="hidden" name="channel_map_id" value="<?= (int)$map['channel_map_id'] ?>" />
                      <select class="input" name="event_key">
                        <?php foreach ($discordEventOptions as $eventKey => $label): ?>
                          <option value="<?= htmlspecialchars($eventKey, ENT_QUOTES, 'UTF-8') ?>" <?= (string)($map['event_key'] ?? '') === $eventKey ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <input class="input" name="channel_id" value="<?= htmlspecialchars((string)($map['channel_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="123456789012345678" />
                      <label style="display:flex; gap:6px; align-items:center;">
                        <input type="checkbox" name="is_enabled" <?= !empty($map['is_enabled']) ? 'checked' : '' ?> />
                        <span class="muted">Enabled</span>
                      </label>
                      <button class="btn" type="submit" name="action" value="save_channel_map">Save</button>
                      <button class="btn ghost" type="submit" name="action" value="delete_channel_map" onclick="return confirm('Delete this channel mapping?')">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div class="muted" style="margin-top:12px;">No channel mappings yet.</div>
        <?php endif; ?>
      </div>
    </section>

    <section class="admin-section" id="templates" data-section="templates">
      <div class="admin-section__title">Templates</div>
      <div class="muted">Customize embed templates for Discord event notifications.</div>
      <div style="margin-top:12px;">
        <?php foreach ($discordEventOptions as $eventKey => $label): ?>
          <?php
            $customTemplate = $templatesByKey[$eventKey] ?? null;
            $defaults = $templateDefaults[$eventKey] ?? ['title' => '', 'body' => '', 'footer' => ''];
          ?>
          <div class="card" style="padding:12px; margin-bottom:12px;">
            <form method="post">
              <input type="hidden" name="event_key" value="<?= htmlspecialchars($eventKey, ENT_QUOTES, 'UTF-8') ?>" />
              <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                  <strong><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></strong>
                  <div class="muted" style="font-size:12px;">Event key: <?= htmlspecialchars($eventKey, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php if ($customTemplate): ?>
                  <span class="pill">Custom</span>
                <?php else: ?>
                  <span class="pill">Default</span>
                <?php endif; ?>
              </div>
              <div class="row" style="margin-top:12px; align-items:flex-start;">
                <div style="flex:1;">
                  <div class="label">Title template</div>
                  <textarea class="input" name="title_template" rows="2"><?= htmlspecialchars($customTemplate['title'] ?? $defaults['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div style="flex:2;">
                  <div class="label">Body template</div>
                  <textarea class="input" name="body_template" rows="4"><?= htmlspecialchars($customTemplate['body'] ?? $defaults['body'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div style="flex:1;">
                  <div class="label">Footer template</div>
                  <textarea class="input" name="footer_template" rows="2"><?= htmlspecialchars($customTemplate['footer'] ?? $defaults['footer'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
              </div>
              <div class="muted" style="margin-top:8px;">Placeholders use {token} format, e.g. {request_code}, {pickup}, {delivery}.</div>
              <div class="row" style="margin-top:10px; gap:10px;">
                <button class="btn" type="submit" name="action" value="save_template">Save Template</button>
                <button class="btn ghost" type="submit" name="action" value="reset_template" <?= $customTemplate ? '' : 'disabled' ?>>Reset to Default</button>
              </div>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="admin-section" id="outbox" data-section="outbox" data-live-section data-live-interval="15" data-live-url="<?= ($basePath ?: '') ?>/admin/discord/partials/outbox.php">
      <?php require __DIR__ . '/../../../src/Views/partials/admin/discord_outbox.php'; ?>
    </section>

    <section class="admin-section" id="tests-status" data-section="tests-status" data-live-section data-live-interval="20" data-live-url="<?= ($basePath ?: '') ?>/admin/discord/partials/status.php">
      <?php require __DIR__ . '/../../../src/Views/partials/admin/discord_status.php'; ?>
    </section>

    <?php if ($isAdmin): ?>
      <section class="admin-section" id="metaverse" data-section="metaverse">
        <div class="admin-section__title">Discord Metaverse (Admin only)</div>
        <div class="muted">Portal user coverage for Discord linking and onboarding processing.</div>
        <div class="row" style="margin-top:12px; gap:12px;">
          <div class="card" style="padding:12px;">
            <div class="label">Portal users</div>
            <div style="font-size:20px; font-weight:600;"><?= count($portalUsers) ?></div>
          </div>
          <div class="card" style="padding:12px;">
            <div class="label">Linked Discord users</div>
            <div style="font-size:20px; font-weight:600;">
              <?= count(array_filter($portalUsers, static fn(array $user): bool => trim((string)($user['discord_user_id'] ?? '')) !== '')) ?>
            </div>
          </div>
          <div class="card" style="padding:12px;">
            <div class="label">Discord hauling members</div>
            <div style="font-size:20px; font-weight:600;"><?= count($discordRoleMembers) ?></div>
          </div>
          <div class="card" style="padding:12px;">
            <div class="label">Discord hauling haulers</div>
            <div style="font-size:20px; font-weight:600;"><?= count($discordHaulerMembers) ?></div>
          </div>
          <div class="card" style="padding:12px;">
            <div class="label">Onboarding scans queued</div>
            <div style="font-size:20px; font-weight:600;"><?= count($onboardingScanRows) ?></div>
          </div>
          <div class="card" style="padding:12px;">
            <div class="label">Pending onboarding DMs</div>
            <div style="font-size:20px; font-weight:600;"><?= count($onboardingDmRows) ?></div>
          </div>
        </div>

        <div class="card" style="padding:12px; margin-top:12px;">
          <div class="label">Portal → Discord status map</div>
          <div class="muted" style="margin-top:6px;">
            "Scan processed" means a linked user has no onboarding DM queued. Entitlement is derived from the latest Discord role snapshot; stale snapshots default to not entitled.
          </div>
          <div class="muted" style="margin-top:4px;">Tip: click a column header to sort.</div>
          <?php if ($portalUsers === []): ?>
            <div class="muted" style="margin-top:12px;">No portal users found.</div>
          <?php else: ?>
            <table class="table" data-sortable-table style="margin-top:12px;">
              <thead>
                <tr>
                  <th data-sort-type="string">User</th>
                  <th data-sort-type="string">Portal status</th>
                  <th data-sort-type="string">Link state</th>
                  <th data-sort-type="string">Discord link</th>
                  <th data-sort-type="string">In scope</th>
                  <th data-sort-type="string">Entitled (hauling.member)</th>
                  <th data-sort-type="string">Access</th>
                  <th data-sort-type="string">Compliance</th>
                  <th data-sort-type="string">Discord hauling.hauler</th>
                  <th data-sort-type="date">Discord last seen</th>
                  <th data-sort-type="string">Scan status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($portalUsers as $user): ?>
                  <?php
                    $discordUserId = trim((string)($user['discord_user_id'] ?? ''));
                    $discordUsername = trim((string)($user['discord_username'] ?? ''));
                    $discordLabel = $discordUsername !== '' ? $discordUsername : ($discordUserId !== '' ? $discordUserId : 'Not linked');
                    $discordSnapshotFresh = AuthzService::isSnapshotFresh($discordRoleSnapshotAt, $snapshotMaxAge);
                    $discordMemberEntitled = $discordSnapshotFresh
                      && $discordUserId !== ''
                      && isset($discordRoleMembersById[$discordUserId]);
                    $discordMemberRightStatus = $discordUserId === ''
                      ? 'Not linked'
                      : ($discordSnapshotFresh ? ($discordMemberEntitled ? 'Entitled' : 'Not entitled') : 'Unknown (stale)');
                    $discordHaulerRightStatus = $discordUserId === '' ? '—' : (isset($discordHaulerMembersById[$discordUserId]) ? 'Yes' : 'No');
                    $linkState = $discordUserId === '' ? 'Not linked' : 'Linked';
                    $inScope = $authz ? $authz->isUserInScope($user, $accessConfig) : false;
                    $accessGranted = $inScope && $discordMemberEntitled && (string)($user['status'] ?? '') === 'active';
                    $accessLabel = $accessGranted ? 'Granted' : 'Revoked';
                    $compliance = AuthzService::classifyCompliance($inScope, $discordMemberEntitled);
                    $hasPendingOnboarding = $discordUserId !== '' && isset($pendingOnboardingByDiscord[$discordUserId]);
                    $onboardingStatus = $discordUserId === '' ? 'Not linked' : ($hasPendingOnboarding ? 'Pending onboarding DM' : 'Scan processed');
                    $lastSeenAt = trim((string)($user['last_seen_at'] ?? ''));
                    $lastSeenSort = $lastSeenAt !== '' ? (int)strtotime($lastSeenAt) : 0;
                  ?>
                  <tr>
                    <td>
                      <div style="font-weight:600;"><?= htmlspecialchars((string)($user['display_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                      <div class="muted" style="font-size:12px;">
                        <?= htmlspecialchars((string)($user['email'] ?? 'No email'), ENT_QUOTES, 'UTF-8') ?>
                      </div>
                    </td>
                    <td><?= htmlspecialchars((string)($user['status'] ?? 'active'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($linkState, ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($discordLabel, ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($inScope ? 'Yes' : 'No', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($discordMemberRightStatus, ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($accessLabel, ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                      <span class="<?= htmlspecialchars($compliance['class'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($compliance['icon'] . ' ' . $compliance['label'], ENT_QUOTES, 'UTF-8') ?>
                      </span>
                    </td>
                    <td><?= htmlspecialchars($discordHaulerRightStatus, ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-sort-value="<?= $lastSeenSort ?>">
                      <?= htmlspecialchars($lastSeenAt !== '' ? $lastSeenAt : '—', ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td><?= htmlspecialchars($onboardingStatus, ENT_QUOTES, 'UTF-8') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="card" style="padding:12px; margin-top:12px;">
          <div class="label">Discord hauling.member roster</div>
          <div class="muted" style="margin-top:6px;">
            Full Discord members list for the hauling.member role. Linked portal users are shown when available.
          </div>
          <?php if ($discordRoleSnapshotAt): ?>
            <div class="muted" style="margin-top:4px;">Snapshot taken: <?= htmlspecialchars($discordRoleSnapshotAt, ENT_QUOTES, 'UTF-8') ?>.</div>
          <?php endif; ?>
          <?php if ($discordRoleStatus): ?>
            <div class="muted" style="margin-top:12px;"><?= htmlspecialchars($discordRoleStatus, ENT_QUOTES, 'UTF-8') ?></div>
          <?php elseif ($discordRoleMembers === []): ?>
            <div class="muted" style="margin-top:12px;">No Discord members found with the hauling.member role.</div>
          <?php else: ?>
            <table class="table" data-sortable-table style="margin-top:12px;">
              <thead>
                <tr>
                  <th data-sort-type="string">Discord user</th>
                  <th data-sort-type="string">Discord ID</th>
                  <th data-sort-type="string">Linked portal user</th>
                  <th data-sort-type="string">Portal status</th>
                  <th data-sort-type="date">Joined at</th>
                  <th data-sort-type="string">Roles</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($discordRoleMembers as $member): ?>
                  <?php
                    $discordUserId = trim((string)($member['discord_user_id'] ?? ''));
                    $nickname = trim((string)($member['nickname'] ?? ''));
                    $globalName = trim((string)($member['global_name'] ?? ''));
                    $username = trim((string)($member['username'] ?? ''));
                    $discriminator = trim((string)($member['discriminator'] ?? ''));
                    $displayName = $nickname !== '' ? $nickname : ($globalName !== '' ? $globalName : $username);
                    $handle = $username;
                    if ($handle !== '' && $discriminator !== '' && $discriminator !== '0') {
                      $handle .= '#' . $discriminator;
                    }
                    $linkedPortal = $discordUserId !== '' ? ($linkedPortalByDiscord[$discordUserId] ?? null) : null;
                    $linkedLabel = $linkedPortal ? (string)($linkedPortal['display_name'] ?? '') : 'Not linked';
                    $linkedStatus = $linkedPortal ? (string)($linkedPortal['status'] ?? 'active') : '—';
                    $joinedAt = trim((string)($member['joined_at'] ?? ''));
                    $joinedSort = $joinedAt !== '' ? (int)strtotime($joinedAt) : 0;
                    $roles = $member['roles'] ?? [];
                    if (!is_array($roles)) {
                      $roles = [];
                    }
                    $rolesLabel = $roles !== [] ? implode(', ', $roles) : '—';
                  ?>
                  <tr>
                    <td>
                      <div style="font-weight:600;"><?= htmlspecialchars($displayName !== '' ? $displayName : $discordUserId, ENT_QUOTES, 'UTF-8') ?></div>
                      <?php if ($handle !== '' && $handle !== $displayName): ?>
                        <div class="muted" style="font-size:12px;"><?= htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') ?></div>
                      <?php endif; ?>
                    </td>
                    <td><code><?= htmlspecialchars($discordUserId !== '' ? $discordUserId : 'Unknown', ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><?= htmlspecialchars($linkedLabel, ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($linkedStatus, ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-sort-value="<?= $joinedSort ?>">
                      <?= htmlspecialchars($joinedAt !== '' ? $joinedAt : '—', ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td><?= htmlspecialchars($rolesLabel, ENT_QUOTES, 'UTF-8') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="card" style="padding:12px; margin-top:12px;">
          <div class="label">Discord hauling.hauler roster</div>
          <div class="muted" style="margin-top:6px;">
            Full Discord members list for the hauling.hauler role. Linked portal users are shown when available.
          </div>
          <?php if ($discordHaulerSnapshotAt): ?>
            <div class="muted" style="margin-top:4px;">Snapshot taken: <?= htmlspecialchars($discordHaulerSnapshotAt, ENT_QUOTES, 'UTF-8') ?>.</div>
          <?php endif; ?>
          <?php if ($discordHaulerStatus): ?>
            <div class="muted" style="margin-top:12px;"><?= htmlspecialchars($discordHaulerStatus, ENT_QUOTES, 'UTF-8') ?></div>
          <?php elseif ($discordHaulerMembers === []): ?>
            <div class="muted" style="margin-top:12px;">No Discord members found with the hauling.hauler role.</div>
          <?php else: ?>
            <table class="table" data-sortable-table style="margin-top:12px;">
              <thead>
                <tr>
                  <th data-sort-type="string">Discord user</th>
                  <th data-sort-type="string">Discord ID</th>
                  <th data-sort-type="string">Linked portal user</th>
                  <th data-sort-type="string">Portal status</th>
                  <th data-sort-type="date">Joined at</th>
                  <th data-sort-type="string">Roles</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($discordHaulerMembers as $member): ?>
                  <?php
                    $discordUserId = trim((string)($member['discord_user_id'] ?? ''));
                    $nickname = trim((string)($member['nickname'] ?? ''));
                    $globalName = trim((string)($member['global_name'] ?? ''));
                    $username = trim((string)($member['username'] ?? ''));
                    $discriminator = trim((string)($member['discriminator'] ?? ''));
                    $displayName = $nickname !== '' ? $nickname : ($globalName !== '' ? $globalName : $username);
                    $handle = $username;
                    if ($handle !== '' && $discriminator !== '' && $discriminator !== '0') {
                      $handle .= '#' . $discriminator;
                    }
                    $linkedPortal = $discordUserId !== '' ? ($linkedPortalByDiscord[$discordUserId] ?? null) : null;
                    $linkedLabel = $linkedPortal ? (string)($linkedPortal['display_name'] ?? '') : 'Not linked';
                    $linkedStatus = $linkedPortal ? (string)($linkedPortal['status'] ?? 'active') : '—';
                    $joinedAt = trim((string)($member['joined_at'] ?? ''));
                    $joinedSort = $joinedAt !== '' ? (int)strtotime($joinedAt) : 0;
                    $roles = $member['roles'] ?? [];
                    if (!is_array($roles)) {
                      $roles = [];
                    }
                    $rolesLabel = $roles !== [] ? implode(', ', $roles) : '—';
                  ?>
                  <tr>
                    <td>
                      <div style="font-weight:600;"><?= htmlspecialchars($displayName !== '' ? $displayName : $discordUserId, ENT_QUOTES, 'UTF-8') ?></div>
                      <?php if ($handle !== '' && $handle !== $displayName): ?>
                        <div class="muted" style="font-size:12px;"><?= htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') ?></div>
                      <?php endif; ?>
                    </td>
                    <td><code><?= htmlspecialchars($discordUserId !== '' ? $discordUserId : 'Unknown', ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><?= htmlspecialchars($linkedLabel, ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($linkedStatus, ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-sort-value="<?= $joinedSort ?>">
                      <?= htmlspecialchars($joinedAt !== '' ? $joinedAt : '—', ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td><?= htmlspecialchars($rolesLabel, ENT_QUOTES, 'UTF-8') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>
  </div>
</section>
<script src="<?= ($basePath ?: '') ?>/assets/js/admin/admin-tabs.js" defer></script>
<script src="<?= ($basePath ?: '') ?>/assets/js/admin/live-sections.js" defer></script>
<script>
  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-outbox-help-toggle]');
    if (!button) {
      return;
    }
    const targetId = button.getAttribute('data-target');
    if (!targetId) {
      return;
    }
    const panel = document.getElementById(targetId);
    if (!panel) {
      return;
    }
    const isHidden = panel.style.display === 'none' || panel.style.display === '';
    panel.style.display = isHidden ? 'table-row' : 'none';
    button.textContent = isHidden ? 'Help ▾' : 'Help ▸';
    button.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
  });

  const parseSortValue = (value, type) => {
    if (type === 'number') {
      const numeric = Number.parseFloat(value);
      return Number.isNaN(numeric) ? 0 : numeric;
    }
    if (type === 'date') {
      const trimmed = value.toString().trim();
      if (trimmed === '') {
        return 0;
      }
      if (/^\d+$/.test(trimmed)) {
        return Number.parseInt(trimmed, 10) * 1000;
      }
      const parsed = Date.parse(trimmed);
      return Number.isNaN(parsed) ? 0 : parsed;
    }
    return value.toString().toLowerCase();
  };

  document.querySelectorAll('[data-sortable-table]').forEach((table) => {
    const tbody = table.querySelector('tbody');
    if (!tbody) {
      return;
    }
    const headers = Array.from(table.querySelectorAll('th[data-sort-type]'));
    headers.forEach((header, index) => {
      header.style.cursor = 'pointer';
      header.addEventListener('click', () => {
        const current = header.getAttribute('aria-sort');
        const next = current === 'ascending' ? 'descending' : 'ascending';
        headers.forEach((item) => item.removeAttribute('aria-sort'));
        header.setAttribute('aria-sort', next);
        const type = header.getAttribute('data-sort-type') || 'string';
        const rows = Array.from(tbody.querySelectorAll('tr'));
        rows.sort((rowA, rowB) => {
          const cellA = rowA.children[index];
          const cellB = rowB.children[index];
          const rawA = cellA?.getAttribute('data-sort-value') ?? cellA?.textContent ?? '';
          const rawB = cellB?.getAttribute('data-sort-value') ?? cellB?.textContent ?? '';
          const valueA = parseSortValue(rawA, type);
          const valueB = parseSortValue(rawB, type);
          if (type === 'string') {
            const result = valueA.localeCompare(valueB);
            return next === 'ascending' ? result : -result;
          }
          if (valueA === valueB) {
            return 0;
          }
          return next === 'ascending' ? (valueA > valueB ? 1 : -1) : (valueA < valueB ? 1 : -1);
        });
        rows.forEach((row) => tbody.appendChild(row));
      });
    });
  });
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
?>
