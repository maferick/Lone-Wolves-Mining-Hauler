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
  'haul.contract.picked_up' => 'Contract picked up',
  'contract.picked_up' => 'Contract picked up (reconciled)',
  'contract.delivered' => 'Contract delivered (reconciled)',
  'contract.failed' => 'Contract failed (reconciled)',
  'contract.expired' => 'Contract expired (reconciled)',
  'haul.assignment.created' => 'Haul assigned',
  'haul.assignment.picked_up' => 'Haul picked up',
  'esi.contracts.pulled' => 'Contracts pulled (ESI)',
  'esi.contracts.reconciled' => 'Contracts reconciled (ESI)',
  'webhook.test' => 'Manual test',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'create') {
    $name = trim((string)($_POST['name'] ?? 'Discord Webhook'));
    $url = trim((string)($_POST['url'] ?? ''));
    if ($url === '') $msg = "Webhook URL required.";
    else {
      $webhookId = $db->insert(
        "INSERT INTO discord_webhook (corp_id, webhook_name, webhook_url, is_enabled)
         VALUES (:cid, :n, :u, 1)",
        ['cid'=>$corpId,'n'=>$name,'u'=>$url]
      );
      foreach ($eventOptions as $eventKey => $label) {
        $db->insert('discord_webhook_event', [
          'webhook_id' => $webhookId,
          'event_key' => $eventKey,
          'is_enabled' => 1,
        ]);
      }
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
  } elseif ($action === 'toggle_contract_link') {
    $id = (int)($_POST['webhook_id'] ?? 0);
    $db->execute(
      "UPDATE discord_webhook
          SET notify_on_contract_link = 1 - notify_on_contract_link
        WHERE webhook_id = :id AND corp_id = :cid",
      ['id' => $id, 'cid' => $corpId]
    );
    $db->audit($corpId, $authCtx['user_id'], $authCtx['character_id'], 'webhook.contract_link.toggle', 'discord_webhook', (string)$id, null, null, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
    $msg = "Updated.";
  } elseif ($action === 'delete') {
    $id = (int)($_POST['webhook_id'] ?? 0);
    $db->execute("DELETE FROM discord_webhook WHERE webhook_id=:id AND corp_id=:cid", ['id'=>$id,'cid'=>$corpId]);
    $db->audit($corpId, $authCtx['user_id'], $authCtx['character_id'], 'webhook.delete', 'discord_webhook', (string)$id, null, null, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
    $msg = "Deleted.";
  } elseif ($action === 'events') {
    $selections = $_POST['subscriptions'] ?? [];
    $webhookRows = $db->select(
      "SELECT webhook_id FROM discord_webhook WHERE corp_id = :cid",
      ['cid' => $corpId]
    );
    $rows = [];
    foreach ($eventOptions as $eventKey => $label) {
      foreach ($webhookRows as $hook) {
        $webhookId = (int)$hook['webhook_id'];
        $rows[] = [
          'webhook_id' => $webhookId,
          'event_key' => $eventKey,
          'is_enabled' => !empty($selections[$eventKey][$webhookId]) ? 1 : 0,
        ];
      }
    }
    $db->tx(function (Db $db) use ($corpId, $rows): void {
      $db->execute(
        "DELETE e FROM discord_webhook_event e
          JOIN discord_webhook w ON w.webhook_id = e.webhook_id
         WHERE w.corp_id = :cid",
        ['cid' => $corpId]
      );
      foreach ($rows as $row) {
        $db->insert('discord_webhook_event', $row);
      }
    });
    $db->audit($corpId, $authCtx['user_id'], $authCtx['character_id'], 'webhook.events.update', 'discord_webhook_event', null, null, [
      'subscriptions' => $selections,
    ], $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
    $msg = "Event subscriptions updated.";
  }
}

$hooks = $db->select(
  "SELECT w.webhook_id,
          w.webhook_name,
          w.webhook_url,
          w.is_enabled,
          w.notify_on_contract_link,
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

$subscriptionRows = $db->select(
  "SELECT e.webhook_id, e.event_key, e.is_enabled
     FROM discord_webhook_event e
     JOIN discord_webhook w ON w.webhook_id = e.webhook_id
    WHERE w.corp_id = :cid",
  ['cid' => $corpId]
);
$subscriptions = [];
foreach ($subscriptionRows as $row) {
  $webhookId = (int)$row['webhook_id'];
  $eventKey = (string)$row['event_key'];
  $subscriptions[$webhookId][$eventKey] = (int)$row['is_enabled'];
}


ob_start();
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
      <div class="label">Event Subscriptions</div>
      <div class="muted" style="margin-bottom:10px;">Choose which webhooks receive each event.</div>
      <?php if ($hooks === []): ?>
        <div class="muted">Create a webhook to enable event subscriptions.</div>
      <?php else: ?>
        <table class="table" style="margin-bottom:12px;">
          <thead>
            <tr>
              <th>Event</th>
              <?php foreach ($hooks as $h): ?>
                <th><?= htmlspecialchars((string)$h['webhook_name'], ENT_QUOTES, 'UTF-8') ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($eventOptions as $eventKey => $label): ?>
            <tr>
              <td><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td>
              <?php foreach ($hooks as $h): ?>
                <?php
                  $hookId = (int)$h['webhook_id'];
                  $checked = !isset($subscriptions[$hookId][$eventKey]) || (int)$subscriptions[$hookId][$eventKey] === 1;
                ?>
                <td>
                  <label style="display:flex; align-items:center; gap:6px;">
                    <input type="checkbox" name="subscriptions[<?= htmlspecialchars($eventKey, ENT_QUOTES, 'UTF-8') ?>][<?= $hookId ?>]" <?= $checked ? 'checked' : '' ?> />
                    <span class="muted" style="font-size:12px;">Enable</span>
                  </label>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div style="margin-top:12px;">
          <button class="btn" type="submit">Save Event Subscriptions</button>
        </div>
      <?php endif; ?>
    </form>

    <table class="table">
      <thead>
        <tr>
          <th>Name</th>
          <th>URL</th>
          <th>Enabled</th>
          <th>Contract Linked</th>
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
          <td><?= ((int)($h['notify_on_contract_link'] ?? 0) === 1) ? 'Yes' : 'No' ?></td>
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
              <button class="btn ghost" name="action" value="toggle_contract_link" type="submit">Toggle Contract Link</button>
              <button class="btn" name="action" value="delete" type="submit" onclick="return confirm('Delete webhook?')">Delete</button>
            </form>
            <form method="post" action="<?= ($basePath ?: '') ?>/api/webhooks/discord/test?webhook_id=<?= (int)$h['webhook_id'] ?>" style="margin-top:6px;">
              <button class="btn ghost" type="submit">Send Test</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <form method="post" action="<?= ($basePath ?: '') ?>/api/webhooks/discord/test-events" style="margin-top:18px;">
      <input type="hidden" name="corp_id" value="<?= (int)$corpId ?>" />
      <div class="label">Send Test Events</div>
      <div class="muted" style="margin-bottom:10px;">Choose which webhooks should receive each event.</div>
      <table class="table" style="margin-bottom:12px;">
        <thead>
          <tr>
            <th>Event</th>
            <th>Webhook Targets</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($eventOptions as $eventKey => $label): ?>
          <tr>
            <td><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <?php if ($hooks === []): ?>
                <span class="muted">No webhooks available.</span>
              <?php else: ?>
                <div style="display:flex; flex-wrap:wrap; gap:12px;">
                  <?php foreach ($hooks as $h): ?>
                    <label style="display:flex; align-items:center; gap:6px;">
                      <input type="checkbox" name="event_targets[<?= htmlspecialchars($eventKey, ENT_QUOTES, 'UTF-8') ?>][]" value="<?= (int)$h['webhook_id'] ?>" />
                      <?= htmlspecialchars((string)$h['webhook_name'], ENT_QUOTES, 'UTF-8') ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div style="margin-bottom:12px;">
        <div class="label">Manual test message</div>
        <input class="input" name="webhook_test_message" value="Test notification from hauling." />
        <div class="muted" style="margin-top:6px;">Used for the “Manual test” event.</div>
      </div>
      <button class="btn" type="submit">Queue Test Events</button>
    </form>

    <div style="margin-top:14px;">
      <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/">Back</a>
    </div>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/admin_layout.php';
