<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$corpName = (string)($config['corp']['name'] ?? ($config['app']['name'] ?? 'Corp Hauling'));
$hallOfFameRows = $hallOfFameRows ?? [];
$hallOfShameRows = $hallOfShameRows ?? [];
$completedTotals = $completedTotals ?? ['count' => 0, 'volume_m3' => 0.0, 'reward_isk' => 0.0];
$failedTotals = $failedTotals ?? ['count' => 0, 'volume_m3' => 0.0, 'reward_isk' => 0.0];

$fmtVolume = static function ($value): string {
  return number_format((float)$value, 0, '.', ',') . ' m³';
};
$fmtIsk = static function ($value): string {
  return number_format((float)$value, 0, '.', ',') . ' ISK';
};

ob_start();
?>
<section class="card">
  <div class="card-header">
    <h2>Hall of Fame</h2>
    <p class="muted">Celebrating top haulers and tracking outcomes across <?= htmlspecialchars($corpName, ENT_QUOTES, 'UTF-8') ?>.</p>
  </div>
  <div class="content">
    <div class="row" style="margin-bottom:16px;">
      <div>
        <div class="label">Completed totals</div>
        <div class="kpi-row" style="padding:6px 0 0;">
          <div class="kpi">
            <div class="kpi-label">Trips</div>
            <div class="kpi-value"><?= number_format((int)$completedTotals['count']) ?></div>
          </div>
          <div class="kpi">
            <div class="kpi-label">Total m³</div>
            <div class="kpi-value"><?= $fmtVolume($completedTotals['volume_m3']) ?></div>
          </div>
          <div class="kpi">
            <div class="kpi-label">Total ISK</div>
            <div class="kpi-value"><?= $fmtIsk($completedTotals['reward_isk']) ?></div>
          </div>
        </div>
      </div>
      <div>
        <div class="label">Failed totals</div>
        <div class="kpi-row" style="padding:6px 0 0;">
          <div class="kpi">
            <div class="kpi-label">Trips</div>
            <div class="kpi-value"><?= number_format((int)$failedTotals['count']) ?></div>
          </div>
          <div class="kpi">
            <div class="kpi-label">Total m³</div>
            <div class="kpi-value"><?= $fmtVolume($failedTotals['volume_m3']) ?></div>
          </div>
          <div class="kpi">
            <div class="kpi-label">Total ISK</div>
            <div class="kpi-value"><?= $fmtIsk($failedTotals['reward_isk']) ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:16px;">
      <div class="card-header">
        <h3>Hall of Fame</h3>
        <p class="muted">Top performers by completed or delivered contracts.</p>
      </div>
      <div class="content">
        <?php if (!$hallOfFameRows): ?>
          <p class="muted">No completed hauling history available for this corp yet.</p>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>Hauler</th>
                <th>Completed</th>
                <th>Total m³</th>
                <th>Total ISK</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($hallOfFameRows as $row): ?>
                <tr>
                  <td><?= htmlspecialchars((string)($row['hauler_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= number_format((int)($row['total_count'] ?? 0)) ?></td>
                  <td><?= $fmtVolume($row['total_volume_m3'] ?? 0) ?></td>
                  <td><?= $fmtIsk($row['total_reward_isk'] ?? 0) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h3>Hall of Shame</h3>
        <p class="muted">Failed, expired, rejected, or cancelled contracts.</p>
      </div>
      <div class="content">
        <?php if (!$hallOfShameRows): ?>
          <p class="muted">No failed hauling history available for this corp yet.</p>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>Hauler</th>
                <th>Failed</th>
                <th>Total m³</th>
                <th>Total ISK</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($hallOfShameRows as $row): ?>
                <tr>
                  <td><?= htmlspecialchars((string)($row['hauler_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= number_format((int)($row['total_count'] ?? 0)) ?></td>
                  <td><?= $fmtVolume($row['total_volume_m3'] ?? 0) ?></td>
                  <td><?= $fmtIsk($row['total_reward_isk'] ?? 0) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
