<div class="live-section__status" data-live-status aria-live="polite">Live</div>
<div class="admin-section__title">Tests &amp; Status</div>
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
          <div class="row" style="align-items:center; gap:8px; margin-top:6px;">
            <span class="pill <?= $checkTone ?>"><?= $checkOk ? 'pass' : ($checkRequired ? 'fail' : 'warn') ?></span>
            <div>
              <div><?= htmlspecialchars($checkLabel, ENT_QUOTES, 'UTF-8') ?></div>
              <?php if ($checkMessage !== '' || $checkStatus > 0): ?>
                <div class="muted" style="font-size:12px;">
                  <?= htmlspecialchars($checkMessage !== '' ? $checkMessage : 'HTTP ' . $checkStatus, ENT_QUOTES, 'UTF-8') ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
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
</div>
