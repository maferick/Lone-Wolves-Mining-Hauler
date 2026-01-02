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
$title = $appName . ' • Webhooks';

$msg = null;
$eventOptions = [
  'haul.request.created' => 'Haul request created',
  'haul.quote.created' => 'Quote created',
  'haul.contract.attached' => 'Contract attached',
  'esi.contracts.pulled' => 'Contracts pulled (ESI)',
  'webhook.test' => 'Manual test',
];
$eventDefaults = array_fill_keys(array_keys($eventOptions), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'create') {
    $name = trim((string)($_POST['name'] ?? 'Discord Webhook'));
    $url = trim((string)($_POST['url'] ?? ''));
    if ($url === '') $msg = "Webhook URL required.";
    else {
      $db->execute(
        "INSERT INTO discord_webhook (corp_id, webhook_name, webhook_url, is_enabled)
         VALUES (:cid, :n, :u, 1)",
        ['cid'=>$corpId,'n'=>$name,'u'=>$url]
      );
      $db->audit($corpId, $authCtx['user_id'], $authCtx['character_id'], 'webhook.create', 'discord_webhook', null, null, [
        'webhook_name' => $name,
        'webhook_url' => $url,
      ], $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
      $msg = "Created.";
    }
  } elseif ($action === 'toggle') {
    $id = (int)($_POST['webhook_id'] ?? 0);
    $db->execute("UPDATE discord_webhook SET is_enabled = 1 - is_enabled WHERE webhook_id=:id AND corp_id=:cid", ['id'=>$id,'cid'=>$corpId]);
    $db->audit($corpId, $authCtx['user_id'], $authCtx['character_id'], 'webhook.toggle', 'discord_webhook', (string)$id, null, null, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
    $msg = "Updated.";
  } elseif ($action === 'delete') {
    $id = (int)($_POST['webhook_id'] ?? 0);
    $db->execute("DELETE FROM discord_webhook WHERE webhook_id=:id AND corp_id=:cid", ['id'=>$id,'cid'=>$corpId]);
    $db->audit($corpId, $authCtx['user_id'], $authCtx['character_id'], 'webhook.delete', 'discord_webhook', (string)$id, null, null, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
    $msg = "Deleted.";
  } elseif ($action === 'events') {
    $updates = [];
    foreach ($eventOptions as $eventKey => $label) {
      $updates[$eventKey] = isset($_POST['events'][$eventKey]);
    }
    $db->execute(
      "INSERT INTO app_setting (corp_id, setting_key, setting_json, updated_by_user_id)
       VALUES (:cid, :key, :json, :uid)
       ON DUPLICATE KEY UPDATE setting_json = VALUES(setting_json), updated_by_user_id = VALUES(updated_by_user_id)",
      [
        'cid' => $corpId,
        'key' => 'discord.webhook_events',
        'json' => Db::jsonEncode($updates),
        'uid' => $authCtx['user_id'],
      ]
    );
    $db->audit($corpId, $authCtx['user_id'], $authCtx['character_id'], 'webhook.events.update', 'app_setting', 'discord.webhook_events', null, [
      'events' => $updates,
    ], $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
    $eventDefaults = array_replace($eventDefaults, $updates);
    $msg = "Event settings updated.";
  }
}

$eventSettingRow = $db->one(
  "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = :key LIMIT 1",
  ['cid' => $corpId, 'key' => 'discord.webhook_events']
);
$eventSettings = $eventDefaults;
if ($eventSettingRow && !empty($eventSettingRow['setting_json'])) {
  $decoded = Db::jsonDecode((string)$eventSettingRow['setting_json'], []);
  if (is_array($decoded)) {
    $eventSettings = array_replace($eventSettings, $decoded);
  }
}

$hooks = $db->select(
  "SELECT w.webhook_id,
          w.webhook_name,
          w.webhook_url,
          w.is_enabled,
          d.status AS last_status,
          d.attempts AS last_attempts,
          d.last_error,
          d.last_http_status,
          d.sent_at AS last_sent_at,
          d.created_at AS last_delivery_at
     FROM discord_webhook w
     LEFT JOIN webhook_delivery d
       ON d.delivery_id = (
          SELECT wd.delivery_id
            FROM webhook_delivery wd
           WHERE wd.webhook_id = w.webhook_id
           ORDER BY wd.created_at DESC
           LIMIT 1
       )
    WHERE w.corp_id = :cid
    ORDER BY w.webhook_id DESC",
  ['cid' => $corpId]
);

$apiKey = (string)($config['security']['api_key'] ?? '');

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="card">
  <div class="card-header">
    <h2>Discord Webhooks</h2>
    <p class="muted">These are used for automated contract postings and operational notifications.</p>
  </div>

  <div class="content">
    <?php if ($msg): ?><div class="pill"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <form method="post" style="margin-bottom:12px;">
      <input type="hidden" name="action" value="create" />
      <div class="row">
        <div>
          <div class="label">Name</div>
          <input class="input" name="name" placeholder="Hauling Board" />
        </div>
        <div>
          <div class="label">Webhook URL</div>
          <input class="input" name="url" placeholder="https://discord.com/api/webhooks/..." />
        </div>
      </div>
      <div style="margin-top:12px;">
        <button class="btn" type="submit">Create</button>
      </div>
    </form>

    <form method="post" style="margin-bottom:18px;">
      <input type="hidden" name="action" value="events" />
      <div class="label">Event Triggers</div>
      <div class="muted" style="margin-bottom:10px;">Toggle which events enqueue Discord webhook deliveries.</div>
      <div class="row" style="gap:16px; flex-wrap:wrap;">
        <?php foreach ($eventOptions as $eventKey => $label): ?>
          <label style="display:flex; align-items:center; gap:6px;">
            <input type="checkbox" name="events[<?= htmlspecialchars($eventKey, ENT_QUOTES, 'UTF-8') ?>]" <?= !empty($eventSettings[$eventKey]) ? 'checked' : '' ?> />
            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
          </label>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:12px;">
        <button class="btn" type="submit">Save Event Settings</button>
      </div>
    </form>

    <table class="table">
      <thead>
        <tr>
          <th>Name</th>
          <th>URL</th>
          <th>Enabled</th>
          <th>Last Delivery</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($hooks as $h): ?>
        <tr>
          <td><?= htmlspecialchars((string)$h['webhook_name'], ENT_QUOTES, 'UTF-8') ?></td>
          <td style="max-width:520px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
            <?= htmlspecialchars((string)$h['webhook_url'], ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td><?= ((int)$h['is_enabled'] === 1) ? 'Yes' : 'No' ?></td>
          <td>
            <?php
              $status = $h['last_status'] ?? null;
              $attempts = (int)($h['last_attempts'] ?? 0);
              $httpStatus = $h['last_http_status'] ?? null;
              $error = trim((string)($h['last_error'] ?? ''));
              $errorSnippet = $error !== '' ? (strlen($error) > 140 ? substr($error, 0, 140) . '…' : $error) : '';
            ?>
            <?php if ($status): ?>
              <div><strong><?= htmlspecialchars((string)$status, ENT_QUOTES, 'UTF-8') ?></strong> (<?= $attempts ?> attempts)</div>
              <div class="muted" style="font-size:12px;">
                HTTP <?= htmlspecialchars((string)($httpStatus ?? 'n/a'), ENT_QUOTES, 'UTF-8') ?>
                <?php if (!empty($h['last_sent_at'])): ?>
                  • Sent <?= htmlspecialchars((string)$h['last_sent_at'], ENT_QUOTES, 'UTF-8') ?>
                <?php elseif (!empty($h['last_delivery_at'])): ?>
                  • Created <?= htmlspecialchars((string)$h['last_delivery_at'], ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
              </div>
              <?php if ($errorSnippet !== ''): ?>
                <div class="muted" style="font-size:12px; margin-top:4px;"><?= htmlspecialchars($errorSnippet, ENT_QUOTES, 'UTF-8') ?></div>
              <?php endif; ?>
            <?php else: ?>
              <span class="muted">No deliveries yet.</span>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" style="display:flex; gap:8px;">
              <input type="hidden" name="webhook_id" value="<?= (int)$h['webhook_id'] ?>" />
              <button class="btn ghost" name="action" value="toggle" type="submit">Toggle</button>
              <button class="btn" name="action" value="delete" type="submit" onclick="return confirm('Delete webhook?')">Delete</button>
            </form>
            <form method="post" action="<?= ($basePath ?: '') ?>/api/webhooks/discord/test?webhook_id=<?= (int)$h['webhook_id'] ?><?= $apiKey !== '' ? '&amp;api_key=' . urlencode($apiKey) : '' ?>" style="margin-top:6px;">
              <button class="btn ghost" type="submit">Send Test</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div style="margin-top:14px;">
      <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/">Back</a>
    </div>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
