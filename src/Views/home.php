<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);
$isLoggedIn = !empty($authCtx['user_id']);
$canAdmin = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'corp.manage');
$displayName = (string)($authCtx['display_name'] ?? 'Guest');
$corpName = (string)($config['corp']['name'] ?? $config['app']['name'] ?? 'Corp Hauling');
$queueStats = $queueStats ?? ['outstanding' => 0, 'in_progress' => 0, 'completed' => 0];
$quoteInput = $quoteInput ?? ['pickup_system' => '', 'destination_system' => '', 'volume' => '', 'collateral' => ''];
$quoteErrors = $quoteErrors ?? [];
$quoteResult = $quoteResult ?? null;

ob_start();
?>
<section class="grid">
  <div class="card">
    <div class="card-header">
      <h2>Queue status</h2>
      <p class="muted">Live snapshot of the hauling queue.</p>
      <?php if ($isLoggedIn): ?>
        <div class="pill subtle" style="margin-top:10px; display:inline-flex;">Signed in as <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
    </div>
    <div class="kpi-row">
      <div class="kpi">
        <div class="kpi-label">Outstanding</div>
        <div class="kpi-value"><?= number_format((int)$queueStats['outstanding']) ?></div>
      </div>
      <div class="kpi">
        <div class="kpi-label">In Progress</div>
        <div class="kpi-value"><?= number_format((int)$queueStats['in_progress']) ?></div>
      </div>
      <div class="kpi">
        <div class="kpi-label">Completed (Last day)</div>
        <div class="kpi-value"><?= number_format((int)$queueStats['completed']) ?></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <h2>Service Update - Dedicated Alliance Service</h2>
      <p class="muted">Priority updates for alliance members.</p>
    </div>
    <ul class="list">
      <li><span class="badge">1</span> Alliance JF Service</li>
      <li><span class="badge">2</span> Mailbox Service</li>
      <li><span class="badge">3</span> Double Wraps/Assembled Containers allowed (note in contract)</li>
      <li><span class="badge">4</span> Discord Consultation</li>
      <li><span class="badge">5</span> JF Round Trip / Bulk discounts</li>
    </ul>
  </div>

  <div class="card" id="quote">
    <div class="card-header">
      <h2>Get Quote</h2>
      <p class="muted">Enter your lane details to receive a preliminary quote.</p>
    </div>
    <form method="post" class="content" action="<?= htmlspecialchars(($basePath ?: '') . '/', ENT_QUOTES, 'UTF-8') ?>">
      <div class="form-grid">
        <label class="form-field">
          <span class="form-label">Pickup system</span>
          <input class="input" type="text" name="pickup_system" value="<?= htmlspecialchars($quoteInput['pickup_system'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Jita" />
        </label>
        <label class="form-field">
          <span class="form-label">Destination system</span>
          <input class="input" type="text" name="destination_system" value="<?= htmlspecialchars($quoteInput['destination_system'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Amarr" />
        </label>
        <label class="form-field">
          <span class="form-label">Volume</span>
          <select class="input" name="volume">
            <option value="">Select volume</option>
            <?php
            $volumes = [
              '12500' => '12,500 m³',
              '62500' => '62,500 m³',
              '360000' => '360,000 m³',
              '950000' => '950,000 m³',
            ];
            foreach ($volumes as $value => $label):
              $selected = $quoteInput['volume'] === $value ? 'selected' : '';
            ?>
              <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $selected ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="form-field">
          <span class="form-label">Collateral</span>
          <input class="input" type="text" name="collateral" value="<?= htmlspecialchars($quoteInput['collateral'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 300m, 2.65b, 400,000,000" />
        </label>
      </div>
      <div class="card-footer">
        <button class="btn" type="submit">Quote</button>
      </div>
      <?php if ($quoteErrors): ?>
        <div class="alert alert-warning">
          <ul>
            <?php foreach ($quoteErrors as $error): ?>
              <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <?php if ($quoteResult): ?>
        <div class="alert alert-success">
          <strong>Quote:</strong> <?= htmlspecialchars($quoteResult['quote'], ENT_QUOTES, 'UTF-8') ?> (<?= number_format($quoteResult['volume']) ?> m³, collateral <?= number_format((float)$quoteResult['collateral'], 2) ?> ISK)
        </div>
      <?php endif; ?>
    </form>
  </div>

  <div class="card">
    <div class="card-header">
      <h2>How to make a contract</h2>
      <p class="muted">Short, corp-specific checklist.</p>
    </div>
    <ul class="list">
      <li><span class="badge">1</span> Get quote</li>
      <li><span class="badge">2</span> Use Janice SELL VALUE as collateral</li>
      <li><span class="badge">3</span> Create private courier contract to <?= htmlspecialchars($corpName, ENT_QUOTES, 'UTF-8') ?></li>
      <li><span class="badge">4</span> Mention assembled containers / wraps in description</li>
      <li><span class="badge">5</span> Join corp Discord channel</li>
    </ul>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
