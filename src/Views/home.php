<?php
declare(strict_types=1);

ob_start();
?>
<section class="grid">
  <div class="card">
    <div class="card-header">
      <h2>Operational Dashboard</h2>
      <p class="muted">Single include pattern: config → db → auth → services.</p>
    </div>
    <div class="kpi-row">
      <div class="kpi">
        <div class="kpi-label">DB</div>
        <div class="kpi-value"><?= htmlspecialchars($dbOk ? 'Online' : 'Offline') ?></div>
      </div>
      <div class="kpi">
        <div class="kpi-label">ESI Cache</div>
        <div class="kpi-value"><?= htmlspecialchars($esiCacheEnabled ? 'Enabled' : 'Disabled') ?></div>
      </div>
      <div class="kpi">
        <div class="kpi-label">Mode</div>
        <div class="kpi-value"><?= htmlspecialchars($env) ?></div>
      </div>
    </div>
    <div class="card-footer">
      <a class="btn" href="/docs">Open Docs</a>
      <a class="btn ghost" href="/health">Health Endpoint</a>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <h2>Next build blocks</h2>
      <p class="muted">Ready for Codex handoff.</p>
    </div>
    <ul class="list">
      <li><span class="badge">1</span> Implement ESI client with ETag + Db cache</li>
      <li><span class="badge">2</span> Pull corp contracts → mirror tables</li>
      <li><span class="badge">3</span> Create quote engine (rules + route cache)</li>
      <li><span class="badge">4</span> Discord webhook posting + delivery retries</li>
    </ul>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
