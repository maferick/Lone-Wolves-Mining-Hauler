<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);
$isLoggedIn = !empty($authCtx['user_id']);
$canAdmin = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'corp.manage');
$displayName = (string)($authCtx['display_name'] ?? 'Guest');

ob_start();
?>
<section class="grid">
  <div class="card">
    <div class="card-header">
      <h2>Operational Dashboard</h2>
      <p class="muted">Single include pattern: config → db → auth → services.</p>
      <?php if ($isLoggedIn): ?>
        <div class="pill subtle" style="margin-top:10px; display:inline-flex;">Signed in as <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
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
      <?php if ($isLoggedIn): ?>
        <?php if ($canAdmin): ?>
          <a class="btn" href="<?= ($basePath ?: '') ?>/admin/">Admin</a>
        <?php endif; ?>
        <a class="btn ghost" href="<?= ($basePath ?: '') ?>/logout/">Logout</a>
      <?php else: ?>
        <a class="btn" href="<?= ($basePath ?: '') ?>/login/">Login (EVE SSO)</a>
      <?php endif; ?>
      <a class="btn ghost" href="<?= ($basePath ?: '') ?>/docs/">Docs</a>
      <a class="btn ghost" href="<?= ($basePath ?: '') ?>/health/">Health</a>
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
