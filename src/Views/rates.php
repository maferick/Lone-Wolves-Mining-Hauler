<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$corpName = (string)($config['corp']['name'] ?? 'Corp');
$ratePlans = $ratePlans ?? [];
$priorityFees = $priorityFees ?? ['normal' => 0.0, 'high' => 0.0];
$securityMultipliers = $securityMultipliers ?? ['low' => 0.5, 'null' => 1.0];
$ratesUpdatedAt = $ratesUpdatedAt ?? null;

$fmtIsk = static function ($value): string {
  return number_format((float)$value, 0, '.', ',') . ' ISK';
};
$fmtPercent = static function ($value, int $precision = 2): string {
  return number_format((float)$value, $precision) . '%';
};

$dashboardUrl = htmlspecialchars(($basePath ?: '') . '/', ENT_QUOTES, 'UTF-8');
?>
<section class="card">
  <div class="card-header">
    <h2>Rates & Pricing</h2>
    <p class="muted">Transparent pricing pulled live from <?= htmlspecialchars($corpName, ENT_QUOTES, 'UTF-8') ?> hauling settings.</p>
  </div>
  <div class="content">
    <div class="stack">
      <div>
        <div class="label">How pricing is calculated</div>
        <ul class="list">
          <li><span class="badge">Base</span> Jump cost = jumps × rate per jump (by ship class).</li>
          <li><span class="badge">Security</span> Low-sec adds <?= $fmtPercent($securityMultipliers['low'] * 100, 0) ?> per low-sec jump; null-sec adds <?= $fmtPercent($securityMultipliers['null'] * 100, 0) ?> per null-sec jump.</li>
          <li><span class="badge">Collateral</span> Collateral fee = collateral × collateral rate.</li>
          <li><span class="badge">Priority</span> Priority fee is added on top (normal/high).</li>
          <li><span class="badge">Minimum</span> Total is never below the minimum price for the ship class.</li>
        </ul>
      </div>
      <div class="grid">
        <div class="card card-subtle">
          <div class="card-header">
            <h3>Rate plans</h3>
            <p class="muted">Per-jump, collateral %, and minimums by ship class.</p>
          </div>
          <div class="content">
            <?php if (!$ratePlans): ?>
              <p class="muted">No rate plans configured yet.</p>
            <?php else: ?>
              <table class="table">
                <thead>
                  <tr>
                    <th>Ship class</th>
                    <th>Rate / Jump</th>
                    <th>Collateral %</th>
                    <th>Minimum fee</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($ratePlans as $plan): ?>
                    <tr>
                      <td><?= htmlspecialchars((string)$plan['service_class'], ENT_QUOTES, 'UTF-8') ?></td>
                      <td><?= $fmtIsk($plan['rate_per_jump']) ?></td>
                      <td><?= $fmtPercent(((float)$plan['collateral_rate']) * 100) ?></td>
                      <td><?= $fmtIsk($plan['min_price']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
            <?php if ($ratesUpdatedAt): ?>
              <p class="muted" style="margin-top:10px;">Last updated <?= htmlspecialchars($ratesUpdatedAt, ENT_QUOTES, 'UTF-8') ?> UTC.</p>
            <?php endif; ?>
          </div>
        </div>
        <div class="card card-subtle">
          <div class="card-header">
            <h3>Priority fees</h3>
            <p class="muted">Applied after jump + collateral pricing.</p>
          </div>
          <div class="content">
            <ul class="list">
              <li><span class="badge">Normal</span> <?= $fmtIsk($priorityFees['normal'] ?? 0) ?></li>
              <li><span class="badge">High</span> <?= $fmtIsk($priorityFees['high'] ?? 0) ?></li>
            </ul>
          </div>
        </div>
      </div>
      <div class="card card-subtle" style="margin-top:16px;">
        <div class="card-header">
          <h3>Ready to book?</h3>
          <p class="muted">Generate a live quote with your route and collateral details.</p>
        </div>
        <div class="content">
          <a class="btn" href="<?= $dashboardUrl ?>#quote">Go to Quote</a>
          <a class="btn ghost" href="<?= $dashboardUrl ?>">Back to dashboard</a>
        </div>
      </div>
    </div>
  </div>
</section>
