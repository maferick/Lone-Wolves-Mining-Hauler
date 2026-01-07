<div class="admin-section__title">Tests &amp; Status</div>
<div class="card" style="padding:12px;">
  <div class="label">Status</div>
  <div class="muted">Bot token configured: <?= $botTokenConfigured ? 'yes' : 'no' ?></div>
  <div class="muted">Public key configured: <?= $publicKeyConfigured ? 'yes' : 'no' ?></div>
  <div class="muted">Guild ID set: <?= !empty($configRow['guild_id']) ? 'yes' : 'no' ?></div>
  <div class="muted">Channel mode: <?= htmlspecialchars((string)($configRow['channel_mode'] ?? 'threads'), ENT_QUOTES, 'UTF-8') ?></div>
  <div class="muted">Ops channel ID: <?= !empty($configRow['hauling_channel_id']) ? htmlspecialchars((string)$configRow['hauling_channel_id'], ENT_QUOTES, 'UTF-8') : '—' ?></div>
  <div class="muted">Thread auto-archive: <?= !empty($configRow['thread_auto_archive_minutes']) ? htmlspecialchars((string)$configRow['thread_auto_archive_minutes'], ENT_QUOTES, 'UTF-8') . ' min' : '—' ?></div>
  <div class="muted">Role mappings: <?= $roleMappingCount ?></div>
  <div class="muted">Last successful bot action: <?= !empty($configRow['last_bot_action_at']) ? htmlspecialchars((string)$configRow['last_bot_action_at'], ENT_QUOTES, 'UTF-8') : '—' ?></div>
  <div class="muted">Last successful delivery: <?= $lastSent !== '' ? htmlspecialchars($lastSent, ENT_QUOTES, 'UTF-8') : '—' ?></div>
  <div class="muted">Pending outbox: <?= $pendingCount ?></div>
  <div class="muted">Last error: <?= $lastError !== '' ? htmlspecialchars($lastError, ENT_QUOTES, 'UTF-8') : '—' ?></div>

  <div style="margin-top:12px;">
    <div class="label">Permission test result</div>
    <?php if ($permissionTest): ?>
      <div class="pill <?= $permissionTestTone === 'success' ? 'pill-success' : ($permissionTestTone === 'danger' ? 'pill-danger' : 'pill-warning') ?>">
        <?= htmlspecialchars($permissionTest, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php else: ?>
      <div class="pill subtle">No permission test result yet.</div>
    <?php endif; ?>
    <?php if (!empty($permissionResults['checks']) && is_array($permissionResults['checks'])): ?>
      <div style="margin-top:8px;">
        <?php foreach ($permissionResults['checks'] as $check): ?>
          <?php
            $checkOk = !empty($check['ok']);
            $checkRequired = isset($check['required']) ? (bool)$check['required'] : true;
            $checkTone = $checkOk ? 'pill-success' : ($checkRequired ? 'pill-danger' : 'pill-warning');
            $checkLabel = (string)($check['label'] ?? 'Check');
            $checkMessage = trim((string)($check['message'] ?? ''));
            $checkStatus = (int)($check['status'] ?? 0);
          ?>
          <?php if ($checkMessage !== '' || $checkStatus > 0): ?>
            <details style="margin-top:6px;">
              <summary class="row" style="align-items:center; gap:8px; cursor:pointer;">
                <span class="pill <?= $checkTone ?>"><?= $checkOk ? 'pass' : ($checkRequired ? 'fail' : 'warn') ?></span>
                <span><?= htmlspecialchars($checkLabel, ENT_QUOTES, 'UTF-8') ?></span>
              </summary>
              <div class="muted" style="font-size:12px; margin-left:28px; margin-top:4px;">
                <?= htmlspecialchars($checkMessage !== '' ? $checkMessage : 'HTTP ' . $checkStatus, ENT_QUOTES, 'UTF-8') ?>
              </div>
            </details>
          <?php else: ?>
            <div class="row" style="align-items:center; gap:8px; margin-top:6px;">
              <span class="pill <?= $checkTone ?>"><?= $checkOk ? 'pass' : ($checkRequired ? 'fail' : 'warn') ?></span>
              <div><?= htmlspecialchars($checkLabel, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

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

  <div style="margin-top:16px;">
    <div class="label">Template tests</div>
    <div class="muted">Send a preview of every template to a delivery target.</div>
    <form method="post" style="margin-top:8px;">
      <input type="hidden" name="action" value="send_template_tests" />
      <div class="row" style="align-items:center; gap:10px;">
        <select class="input" name="template_delivery">
          <option value="bot">Bot (hauling channel)</option>
          <option value="discord">Discord webhook</option>
          <option value="slack">Slack webhook</option>
        </select>
        <input class="input" name="template_webhook_url" placeholder="Webhook URL (required for webhook targets)" />
        <button class="btn" type="submit">Send Template Tests</button>
      </div>
    </form>
  </div>

  <div style="margin-top:16px;">
    <div class="label">Test thread</div>
    <div class="muted">Create a short-lived thread in the hauling channel and auto-close it after a minute or two.</div>
    <form method="post" style="margin-top:8px;">
      <input type="hidden" name="action" value="test_thread" />
      <div class="row" style="align-items:center; gap:10px;">
        <select class="input" name="thread_duration">
          <option value="1">Close after 1 minute</option>
          <option value="2">Close after 2 minutes</option>
        </select>
        <button class="btn ghost" type="submit">Create Test Thread</button>
      </div>
    </form>
  </div>
</div>
