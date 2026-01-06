<div class="admin-section__title">Outbox</div>
<div class="muted">Review queued Discord deliveries and the most recent results.</div>
<div class="card" style="padding:12px; margin-top:12px;">
  <div class="row" style="align-items:center; gap:12px;">
    <div class="muted">Pending outbox: <?= $pendingCount ?></div>
    <form method="post" style="margin-left:auto;">
      <input type="hidden" name="action" value="clear_outbox" />
      <button class="btn ghost" type="submit" onclick="return confirm('Clear queued, sending, and failed outbox entries?');" <?= $pendingCount > 0 ? '' : 'disabled' ?>>Clear Pending Outbox</button>
    </form>
  </div>
  <?php if ($outboxRows !== []): ?>
    <table class="table" style="margin-top:12px;">
      <thead>
        <tr>
          <th>ID</th>
          <th>Event</th>
          <th>Status</th>
          <th>Attempts</th>
          <th>Created</th>
          <th>Next Attempt</th>
          <th>Sent</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($outboxRows as $row): ?>
          <?php
            $status = (string)($row['status'] ?? '');
            $payloadText = (string)($row['payload_json'] ?? '');
            $payloadPreview = $payloadText;
            if (strlen($payloadPreview) > 300) {
              $payloadPreview = substr($payloadPreview, 0, 300) . '…';
            }
            $statusClass = 'pill-warning';
            if ($status === 'sent') {
              $statusClass = 'pill-success';
            } elseif ($status === 'failed') {
              $statusClass = 'pill-danger';
            }
            $helpPanelId = 'outbox-help-' . (int)$row['outbox_id'];
            $normalizedError = null;
            if ($status === 'failed' && !empty($row['last_error'])) {
              $normalizedError = App\Services\DiscordOutboxErrorHelp::normalize((string)$row['last_error']);
            }
          ?>
          <tr>
            <td><?= (int)$row['outbox_id'] ?></td>
            <td>
              <div><strong><?= htmlspecialchars($outboxEventLabels[(string)($row['event_key'] ?? '')] ?? (string)($row['event_key'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></div>
              <div class="muted" style="font-size:12px;"><?= htmlspecialchars((string)($row['event_key'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            </td>
            <td><span class="pill <?= $statusClass ?>"><?= htmlspecialchars($status !== '' ? $status : 'unknown', ENT_QUOTES, 'UTF-8') ?></span></td>
            <td><?= (int)($row['attempts'] ?? 0) ?></td>
            <td><?= htmlspecialchars((string)($row['created_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($row['next_attempt_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($row['sent_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <?php if (!empty($row['last_error'])): ?>
                <div class="pill pill-danger" style="margin-bottom:6px;">
                  <?= htmlspecialchars((string)$row['last_error'], ENT_QUOTES, 'UTF-8') ?>
                  <?php if ($status === 'failed'): ?>
                    <button type="button" class="btn ghost" data-outbox-help-toggle data-target="<?= htmlspecialchars($helpPanelId, ENT_QUOTES, 'UTF-8') ?>" aria-expanded="false" style="margin-left:8px; padding:2px 6px; font-size:12px;">
                      Help ▸
                    </button>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <details>
                <summary class="muted" style="cursor:pointer;">Payload</summary>
                <pre style="white-space:pre-wrap; margin-top:6px;"><?= htmlspecialchars($payloadPreview, ENT_QUOTES, 'UTF-8') ?></pre>
              </details>
            </td>
          </tr>
          <?php if ($status === 'failed'): ?>
            <tr id="<?= htmlspecialchars($helpPanelId, ENT_QUOTES, 'UTF-8') ?>" class="outbox-help-row" style="display:none;">
              <td colspan="8">
                <?= $renderOutboxErrorHelp((string)($normalizedError['error_key'] ?? 'discord.unknown_error'), $normalizedError ?? []) ?>
              </td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <div class="muted" style="margin-top:12px;">No outbox messages yet.</div>
  <?php endif; ?>
</div>
