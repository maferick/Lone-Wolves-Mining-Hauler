<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$corpName = (string)($config['corp']['name'] ?? 'Corp');
$ratePlans = $ratePlans ?? [];
$priorityFees = $priorityFees ?? ['normal' => 0.0, 'high' => 0.0];
$securityMultipliers = $securityMultipliers ?? ['high' => 1.0, 'low' => 1.5, 'null' => 2.5, 'pochven' => 3.0, 'zarzakh' => 3.5, 'thera' => 3.0];
$flatRiskFees = $flatRiskFees ?? ['lowsec' => 0.0, 'nullsec' => 0.0, 'special' => 0.0];
$volumePressure = $volumePressure ?? ['enabled' => false, 'thresholds' => []];
$ratesUpdatedAt = $ratesUpdatedAt ?? null;

$fmtIsk = static function ($value): string {
  return number_format((float)$value, 0, '.', ',') . ' ISK';
};
$fmtPercent = static function ($value, int $precision = 2): string {
  return number_format((float)$value, $precision) . '%';
};

$dashboardUrl = htmlspecialchars(($basePath ?: '') . '/', ENT_QUOTES, 'UTF-8');
?>
<section class="card rates-page">
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
          <li><span class="badge">Security</span> Each jump uses the security-class multiplier (high/low/null/special).</li>
          <li><span class="badge">Collateral</span> Collateral fee = collateral × collateral rate.</li>
          <li><span class="badge">Risk</span> Optional flat surcharges apply if a route touches low-sec, null-sec, or special space.</li>
          <li><span class="badge">Volume</span> Optional scaling applies when volume nears max hull capacity.</li>
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
      <div class="grid" style="margin-top:16px;">
        <div class="card card-subtle">
          <div class="card-header">
            <h3>Security multipliers</h3>
            <p class="muted">Applied per jump after the base rate.</p>
          </div>
          <div class="content">
            <ul class="list">
              <li><span class="badge">High-sec</span> × <?= number_format((float)($securityMultipliers['high'] ?? 1.0), 2) ?></li>
              <li><span class="badge">Low-sec</span> × <?= number_format((float)($securityMultipliers['low'] ?? 1.0), 2) ?></li>
              <li><span class="badge">Null-sec</span> × <?= number_format((float)($securityMultipliers['null'] ?? 1.0), 2) ?></li>
              <li><span class="badge">Pochven</span> × <?= number_format((float)($securityMultipliers['pochven'] ?? 1.0), 2) ?></li>
              <li><span class="badge">Zarzakh</span> × <?= number_format((float)($securityMultipliers['zarzakh'] ?? 1.0), 2) ?></li>
              <li><span class="badge">Thera</span> × <?= number_format((float)($securityMultipliers['thera'] ?? 1.0), 2) ?></li>
            </ul>
          </div>
        </div>
        <div class="card card-subtle">
          <div class="card-header">
            <h3>Flat risk surcharges</h3>
            <p class="muted">Added once per route when present.</p>
          </div>
          <div class="content">
            <ul class="list">
              <li><span class="badge">Low-sec</span> <?= $fmtIsk($flatRiskFees['lowsec'] ?? 0) ?></li>
              <li><span class="badge">Null-sec</span> <?= $fmtIsk($flatRiskFees['nullsec'] ?? 0) ?></li>
              <li><span class="badge">Special</span> <?= $fmtIsk($flatRiskFees['special'] ?? 0) ?></li>
            </ul>
          </div>
        </div>
      </div>
      <div class="card card-subtle" style="margin-top:16px;">
        <div class="card-header">
          <h3>Volume pressure scaling</h3>
          <p class="muted">Surcharges when volume reaches a percentage of max hull capacity.</p>
        </div>
        <div class="content">
          <?php if (empty($volumePressure['enabled'])): ?>
            <p class="muted">Volume pressure scaling is disabled.</p>
          <?php else: ?>
            <ul class="list">
              <?php foreach (($volumePressure['thresholds'] ?? []) as $threshold): ?>
                <li>
                  <span class="badge"><?= number_format((float)($threshold['threshold_pct'] ?? 0), 0) ?>%</span>
                  +<?= $fmtPercent((float)($threshold['surcharge_pct'] ?? 0), 0) ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
      <div class="card card-subtle" style="margin-top:16px;">
        <div class="card-header">
          <h3>Ready to book?</h3>
          <p class="muted">Generate a live quote with your route and collateral details.</p>
        </div>
        <div class="content">
          <a class="btn" href="<?= htmlspecialchars(($basePath ?: '') . '/quote', ENT_QUOTES, 'UTF-8') ?>">Go to Quote</a>
          <a class="btn ghost" href="<?= $dashboardUrl ?>">Back to dashboard</a>
        </div>
      </div>
    </div>
  </div>
</section>
