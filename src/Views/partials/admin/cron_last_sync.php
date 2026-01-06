<div class="live-section__status" data-live-status aria-live="polite">Live</div>
<h3 style="margin-top:18px;">Last sync timestamps</h3>
<ul class="muted" style="margin-top:8px;">
  <?php if ($cronStats === []): ?>
    <li>No syncs recorded yet.</li>
  <?php else: ?>
    <?php foreach ($cronStats as $key => $value): ?>
      <li><strong><?= htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') ?></li>
    <?php endforeach; ?>
  <?php endif; ?>
</ul>
