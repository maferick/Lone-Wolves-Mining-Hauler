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

$eventOptions = [
  'request.created' => 'Request created',
  'request.status_changed' => 'Request status changed',
  'contract.matched' => 'Contract matched',
  'contract.picked_up' => 'Contract picked up',
  'contract.completed' => 'Contract completed',
  'contract.failed' => 'Contract failed',
  'contract.expired' => 'Contract expired',
  'alert.system' => 'System alert',
];

$msg = null;
$preview = null;

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'save_config') {
    $current = $loadConfig($db, $corpId);
    $enabledWebhooks = array_key_exists('enabled_webhooks', $_POST)
      ? (!empty($_POST['enabled_webhooks']) ? 1 : 0)
      : (int)($current['enabled_webhooks'] ?? 1);
    $enabledBot = array_key_exists('enabled_bot', $_POST)
      ? (!empty($_POST['enabled_bot']) ? 1 : 0)
      : (int)($current['enabled_bot'] ?? 0);
    $applicationId = array_key_exists('application_id', $_POST)
      ? trim((string)($_POST['application_id'] ?? ''))
      : (string)($current['application_id'] ?? '');
    $guildId = array_key_exists('guild_id', $_POST)
      ? trim((string)($_POST['guild_id'] ?? ''))
      : (string)($current['guild_id'] ?? '');
    $rateLimit = array_key_exists('rate_limit_per_minute', $_POST)
      ? max(1, (int)($_POST['rate_limit_per_minute'] ?? 20))
      : (int)($current['rate_limit_per_minute'] ?? 20);
    $dedupeWindow = array_key_exists('dedupe_window_seconds', $_POST)
      ? max(0, (int)($_POST['dedupe_window_seconds'] ?? 60))
      : (int)($current['dedupe_window_seconds'] ?? 60);
    $commandsEphemeral = array_key_exists('commands_ephemeral_default', $_POST)
      ? (!empty($_POST['commands_ephemeral_default']) ? 1 : 0)
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
    $msg = 'Discord configuration saved.';
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
  } elseif ($action === 'add_channel') {
    $eventKey = (string)($_POST['event_key'] ?? '');
    $mode = (string)($_POST['mode'] ?? 'webhook');
    $channelId = trim((string)($_POST['channel_id'] ?? ''));
    $webhookUrl = trim((string)($_POST['webhook_url'] ?? ''));
    $enabled = !empty($_POST['is_enabled']) ? 1 : 0;

    if ($eventKey !== '' && isset($eventOptions[$eventKey])) {
      $db->insert('discord_channel_map', [
        'corp_id' => $corpId,
        'event_key' => $eventKey,
        'mode' => $mode,
        'channel_id' => $channelId !== '' ? $channelId : null,
        'webhook_url' => $webhookUrl !== '' ? $webhookUrl : null,
        'is_enabled' => $enabled,
      ]);
      $msg = 'Channel mapping added.';
    }
  } elseif ($action === 'update_channel') {
    $mapId = (int)($_POST['channel_map_id'] ?? 0);
    $eventKey = (string)($_POST['event_key'] ?? '');
    $mode = (string)($_POST['mode'] ?? 'webhook');
    $channelId = trim((string)($_POST['channel_id'] ?? ''));
    $webhookUrl = trim((string)($_POST['webhook_url'] ?? ''));
    $enabled = !empty($_POST['is_enabled']) ? 1 : 0;

    if ($mapId > 0 && $eventKey !== '') {
      $db->execute(
        "UPDATE discord_channel_map
            SET event_key = :event_key,
                mode = :mode,
                channel_id = :channel_id,
                webhook_url = :webhook_url,
                is_enabled = :is_enabled,
                updated_at = UTC_TIMESTAMP()
          WHERE channel_map_id = :id AND corp_id = :cid",
        [
          'event_key' => $eventKey,
          'mode' => $mode,
          'channel_id' => $channelId !== '' ? $channelId : null,
          'webhook_url' => $webhookUrl !== '' ? $webhookUrl : null,
          'is_enabled' => $enabled,
          'id' => $mapId,
          'cid' => $corpId,
        ]
      );
      $msg = 'Channel mapping updated.';
    }
  } elseif ($action === 'delete_channel') {
    $mapId = (int)($_POST['channel_map_id'] ?? 0);
    if ($mapId > 0) {
      $db->execute(
        "DELETE FROM discord_channel_map WHERE channel_map_id = :id AND corp_id = :cid",
        ['id' => $mapId, 'cid' => $corpId]
      );
      $msg = 'Channel mapping deleted.';
    }
  } elseif ($action === 'send_test') {
    $mapId = (int)($_POST['channel_map_id'] ?? 0);
    $eventKey = (string)($_POST['event_key'] ?? '');
    if (!empty($services['discord_events']) && $eventKey !== '') {
      $services['discord_events']->enqueueTestMessage($corpId, $eventKey, $mapId ?: null);
      $msg = 'Test message queued.';
    }
  } elseif ($action === 'save_template') {
    $eventKey = (string)($_POST['event_key'] ?? '');
    $titleTemplate = (string)($_POST['title_template'] ?? '');
    $bodyTemplate = (string)($_POST['body_template'] ?? '');
    $footerTemplate = (string)($_POST['footer_template'] ?? '');

    if ($eventKey !== '') {
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
      $msg = 'Templates saved.';
    }
  } elseif ($action === 'reset_templates') {
    if (!empty($services['discord_renderer'])) {
      $renderer = $services['discord_renderer'];
      $defaults = $renderer->defaultTemplates(array_keys($eventOptions));
      foreach ($defaults as $eventKey => $template) {
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
            'title_template' => (string)($template['title'] ?? ''),
            'body_template' => (string)($template['body'] ?? ''),
            'footer_template' => (string)($template['footer'] ?? ''),
          ]
        );
      }
      $msg = 'Templates reset to defaults.';
    }
  } elseif ($action === 'preview_template') {
    $eventKey = (string)($_POST['event_key'] ?? '');
    if ($eventKey !== '' && !empty($services['discord_renderer'])) {
      $mockPayload = [
        'event_key' => $eventKey,
        'request_id' => 1234,
        'request_code' => 'abc123',
        'pickup' => 'Jita IV - Moon 4',
        'delivery' => 'K-6K16',
        'volume' => '12,300',
        'collateral' => '456,000,000.00',
        'reward' => '32,640,000.00',
        'priority' => 'normal',
        'status' => 'requested',
        'user' => 'Pilot Name',
        'requester' => 'Pilot Name',
        'requester_character_id' => 123456789,
        'hauler' => 'Ace Hauler',
        'hauler_character_id' => 987654321,
        'ship_type_id' => 28844,
        'ship_name' => 'Jump Freighter',
        'link_request' => 'https://example.com/request?request_key=abc123',
        'link_contract_instructions' => 'https://example.com/request?request_key=abc123',
      ];
      $renderer = $services['discord_renderer'];
      $preview = $renderer->renderPreview($corpId, $eventKey, $mockPayload);
      $msg = 'Preview generated below.';
    }
  }
}

$configRow = $loadConfig($db, $corpId);
$channelMaps = $db->select(
  "SELECT * FROM discord_channel_map WHERE corp_id = :cid ORDER BY event_key ASC, channel_map_id ASC",
  ['cid' => $corpId]
);
$templates = $db->select(
  "SELECT * FROM discord_template WHERE corp_id = :cid",
  ['cid' => $corpId]
);
if ($templates === [] && !empty($services['discord_renderer'])) {
  $renderer = $services['discord_renderer'];
  $defaults = $renderer->defaultTemplates(array_keys($eventOptions));
  foreach ($defaults as $eventKey => $template) {
    $db->insert('discord_template', [
      'corp_id' => $corpId,
      'event_key' => $eventKey,
      'title_template' => (string)($template['title'] ?? ''),
      'body_template' => (string)($template['body'] ?? ''),
      'footer_template' => (string)($template['footer'] ?? ''),
    ]);
  }
  $templates = $db->select(
    "SELECT * FROM discord_template WHERE corp_id = :cid",
    ['cid' => $corpId]
  );
}
$templateIndex = [];
foreach ($templates as $template) {
  $templateIndex[(string)$template['event_key']] = $template;
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

$botTokenConfigured = !empty($config['discord']['bot_token']);
$publicKeyConfigured = !empty($config['discord']['public_key']);
$discordTabs = [
  ['id' => 'settings', 'label' => 'Settings'],
  ['id' => 'bot', 'label' => 'Bot'],
  ['id' => 'routing', 'label' => 'Routing'],
  ['id' => 'templates', 'label' => 'Templates'],
  ['id' => 'status', 'label' => 'Status'],
];

ob_start();
?>
<section class="card admin-tabs" data-admin-tabs="discord">
  <div class="card-header">
    <h2>Discord Integration</h2>
    <p class="muted">Configure webhooks, bot commands, and notification templates for ops messaging.</p>
    <nav class="admin-subnav admin-subnav--tabs" data-admin-tabs-nav aria-label="Discord sections">
      <?php foreach ($discordTabs as $tab): ?>
        <a class="nav-link" href="<?= ($basePath ?: '') ?>/admin/discord/#<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>" data-section="<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8') ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>

  <div class="content">
    <?php if ($msg): ?><div class="pill"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <section class="admin-section is-active" id="settings" data-section="settings">
      <div class="admin-section__title">Settings</div>
      <div class="card" style="padding:12px;">
        <div class="label">Connection Mode</div>
        <form method="post">
          <input type="hidden" name="action" value="save_config" />
          <label style="display:flex; gap:8px; align-items:center;">
            <input type="checkbox" name="enabled_webhooks" <?= !empty($configRow['enabled_webhooks']) ? 'checked' : '' ?> />
            <span>Enable Discord Webhooks</span>
          </label>
          <label style="display:flex; gap:8px; align-items:center; margin-top:6px;">
            <input type="checkbox" name="enabled_bot" <?= !empty($configRow['enabled_bot']) ? 'checked' : '' ?> />
            <span>Enable Discord Bot</span>
          </label>

          <div class="row" style="margin-top:12px;">
            <div>
              <div class="label">Rate limit (msgs/min/channel)</div>
              <input class="input" type="number" min="1" name="rate_limit_per_minute" value="<?= (int)($configRow['rate_limit_per_minute'] ?? 20) ?>" />
            </div>
            <div>
              <div class="label">Dedupe window (seconds)</div>
              <input class="input" type="number" min="0" name="dedupe_window_seconds" value="<?= (int)($configRow['dedupe_window_seconds'] ?? 60) ?>" />
            </div>
          </div>

          <label style="display:flex; gap:8px; align-items:center; margin-top:10px;">
            <input type="checkbox" name="commands_ephemeral_default" <?= !empty($configRow['commands_ephemeral_default']) ? 'checked' : '' ?> />
            <span>Default ephemeral responses for slash commands</span>
          </label>

          <div style="margin-top:12px;">
            <button class="btn" type="submit">Save Settings</button>
          </div>
        </form>
      </div>
    </section>
    <section class="admin-section" id="status" data-section="status">
      <div class="admin-section__title">Status</div>
      <div class="card" style="padding:12px;">
        <div class="label">Status</div>
        <div class="muted">Bot token configured: <?= $botTokenConfigured ? 'yes' : 'no' ?></div>
        <div class="muted">Public key configured: <?= $publicKeyConfigured ? 'yes' : 'no' ?></div>
        <div class="muted">Webhook URLs configured: <?= $webhookCount ?></div>
        <div class="muted">Last successful delivery: <?= $lastSent !== '' ? htmlspecialchars($lastSent, ENT_QUOTES, 'UTF-8') : '—' ?></div>
        <div class="muted">Pending outbox: <?= $pendingCount ?></div>
        <div class="muted">Last error: <?= $lastError !== '' ? htmlspecialchars($lastError, ENT_QUOTES, 'UTF-8') : '—' ?></div>
      </div>
    </section>

    <section class="admin-section" id="bot" data-section="bot">
      <div class="admin-section__title">Bot</div>
      <div class="card" style="padding:12px; margin-bottom:18px;">
        <div class="label">Bot Configuration</div>
        <form method="post" style="margin-top:8px;">
          <input type="hidden" name="action" value="save_config" />
          <div class="row">
            <div>
              <div class="label">Discord Application ID</div>
              <input class="input" name="application_id" value="<?= htmlspecialchars((string)($configRow['application_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div>
              <div class="label">Guild ID (optional)</div>
              <input class="input" name="guild_id" value="<?= htmlspecialchars((string)($configRow['guild_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
            </div>
          </div>
          <div class="row" style="margin-top:12px;">
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

    <section class="admin-section" id="routing" data-section="routing">
      <div class="admin-section__title">Routing</div>
      <div class="card" style="padding:12px; margin-bottom:18px;">
        <div class="label">Channel Routing</div>
        <form method="post" style="margin-top:10px;">
          <input type="hidden" name="action" value="add_channel" />
          <div class="row">
            <div>
              <div class="label">Event</div>
              <select class="input" name="event_key">
                <?php foreach ($eventOptions as $eventKey => $label): ?>
                  <option value="<?= htmlspecialchars($eventKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <div class="label">Mode</div>
              <select class="input" name="mode">
                <option value="webhook">Webhook</option>
                <option value="bot">Bot</option>
              </select>
            </div>
            <div>
              <div class="label">Channel ID</div>
              <input class="input" name="channel_id" placeholder="123456789" />
            </div>
            <div>
              <div class="label">Webhook URL</div>
              <input class="input" name="webhook_url" placeholder="https://discord.com/api/webhooks/..." />
            </div>
            <div style="display:flex; align-items:end;">
              <label style="display:flex; gap:8px; align-items:center;">
                <input type="checkbox" name="is_enabled" checked />
                <span>Enabled</span>
              </label>
            </div>
          </div>
          <div style="margin-top:10px;">
            <button class="btn" type="submit">Add Mapping</button>
          </div>
        </form>

        <?php if ($channelMaps === []): ?>
          <div class="muted" style="margin-top:12px;">No channel mappings yet.</div>
        <?php else: ?>
          <table class="table" style="margin-top:12px;">
            <thead>
              <tr>
                <th>Event</th>
                <th>Mode</th>
                <th>Channel ID</th>
                <th>Webhook URL</th>
                <th>Enabled</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($channelMaps as $map): ?>
              <tr>
                <td>
                  <form method="post" style="display:flex; gap:6px; align-items:center;">
                    <input type="hidden" name="action" value="update_channel" />
                    <input type="hidden" name="channel_map_id" value="<?= (int)$map['channel_map_id'] ?>" />
                    <select class="input" name="event_key">
                      <?php foreach ($eventOptions as $eventKey => $label): ?>
                        <option value="<?= htmlspecialchars($eventKey, ENT_QUOTES, 'UTF-8') ?>" <?= $eventKey === $map['event_key'] ? 'selected' : '' ?>>
                          <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <select class="input" name="mode">
                      <option value="webhook" <?= $map['mode'] === 'webhook' ? 'selected' : '' ?>>Webhook</option>
                      <option value="bot" <?= $map['mode'] === 'bot' ? 'selected' : '' ?>>Bot</option>
                    </select>
                </td>
                <td>
                    <input class="input" name="channel_id" value="<?= htmlspecialchars((string)($map['channel_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                </td>
                <td>
                    <input class="input" name="webhook_url" value="<?= htmlspecialchars((string)($map['webhook_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                </td>
                <td>
                    <label style="display:flex; gap:6px; align-items:center;">
                      <input type="checkbox" name="is_enabled" <?= !empty($map['is_enabled']) ? 'checked' : '' ?> />
                      <span class="muted" style="font-size:12px;">Enable</span>
                    </label>
                </td>
                <td>
                    <button class="btn" type="submit">Save</button>
                  </form>
                  <form method="post" style="margin-top:6px;">
                    <input type="hidden" name="action" value="send_test" />
                    <input type="hidden" name="channel_map_id" value="<?= (int)$map['channel_map_id'] ?>" />
                    <input type="hidden" name="event_key" value="<?= htmlspecialchars((string)$map['event_key'], ENT_QUOTES, 'UTF-8') ?>" />
                    <button class="btn ghost" type="submit">Send Test Message</button>
                  </form>
                  <form method="post" style="margin-top:6px;">
                    <input type="hidden" name="action" value="delete_channel" />
                    <input type="hidden" name="channel_map_id" value="<?= (int)$map['channel_map_id'] ?>" />
                    <button class="btn danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </section>

    <section class="admin-section" id="templates" data-section="templates">
      <div class="admin-section__title">Templates</div>
      <div class="card" style="padding:12px;">
        <div class="label">Message Templates</div>
        <div class="muted" style="margin-bottom:10px;">Supported tokens: {request_id}, {request_code}, {pickup}, {delivery}, {volume}, {collateral}, {reward}, {status}, {priority}, {user}, {requester}, {hauler}, {ship_name}, {requester_portrait}, {hauler_portrait}, {ship_render}, {link_request}, {link_contract_instructions}</div>
        <form method="post" style="margin-bottom:14px;">
          <input type="hidden" name="action" value="reset_templates" />
          <button class="btn ghost" type="submit">Reset templates to defaults</button>
        </form>
        <?php foreach ($eventOptions as $eventKey => $label): ?>
          <?php $template = $templateIndex[$eventKey] ?? []; ?>
          <form method="post" style="margin-bottom:14px;">
            <input type="hidden" name="event_key" value="<?= htmlspecialchars($eventKey, ENT_QUOTES, 'UTF-8') ?>" />
            <div class="label">Template: <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="row">
              <div>
                <div class="label">Title</div>
                <textarea class="input" name="title_template" rows="2"><?= htmlspecialchars((string)($template['title_template'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
              </div>
              <div>
                <div class="label">Body</div>
                <textarea class="input" name="body_template" rows="4"><?= htmlspecialchars((string)($template['body_template'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
              </div>
              <div>
                <div class="label">Footer</div>
                <textarea class="input" name="footer_template" rows="2"><?= htmlspecialchars((string)($template['footer_template'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
              </div>
            </div>
            <div class="row" style="margin-top:8px; gap:10px;">
              <button class="btn" type="submit" name="action" value="save_template">Save Template</button>
              <button class="btn ghost" type="submit" name="action" value="preview_template">Preview Render</button>
            </div>
          </form>
        <?php endforeach; ?>

        <?php if ($preview): ?>
          <div class="card" style="padding:12px;">
            <div class="label">Preview Render</div>
            <pre style="white-space:pre-wrap; font-size:12px;"><?= htmlspecialchars(Db::jsonEncode($preview), ENT_QUOTES, 'UTF-8') ?></pre>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</section>
<script src="<?= ($basePath ?: '') ?>/assets/js/admin/admin-tabs.js" defer></script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/admin_layout.php';
?>
