<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);
$isLoggedIn = !empty($authCtx['user_id']);
$canViewOps = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.request.read');
$canManageOps = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.request.manage');
$canAssignOps = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.assign');
$canExecuteOps = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.execute');
$queueStats = $queueStats ?? ['outstanding' => 0, 'in_progress' => 0, 'delivered' => 0];
$requests = $requests ?? [];
$requestsAvailable = $requestsAvailable ?? false;
$corpName = (string)($config['corp']['name'] ?? $config['app']['name'] ?? 'Corp Hauling');

ob_start();
?>
<section class="card">
  <div class="card-header">
    <h2>Hauling Operations</h2>
    <p class="muted">View, manage, and assign hauling requests across <?= htmlspecialchars($corpName, ENT_QUOTES, 'UTF-8') ?>.</p>
  </div>
  <div class="content">
    <?php if (!$isLoggedIn): ?>
      <div class="alert alert-warning">
        Sign in to view and manage hauling operations.
      </div>
    <?php else: ?>
      <div class="row" style="margin-bottom:14px;">
        <div>
          <div class="label">Access</div>
          <div>
            <span class="pill">View <?= $canViewOps ? 'Enabled' : 'Limited' ?></span>
            <span class="pill subtle">Manage <?= $canManageOps ? 'Enabled' : 'Limited' ?></span>
            <span class="pill subtle">Assign <?= $canAssignOps ? 'Enabled' : 'Limited' ?></span>
            <span class="pill subtle">Execute <?= $canExecuteOps ? 'Enabled' : 'Limited' ?></span>
          </div>
        </div>
        <div>
          <div class="label">Queue summary</div>
          <div class="kpi-row" style="padding:6px 0 0;">
            <div class="kpi">
              <div class="kpi-label">Outstanding</div>
              <div class="kpi-value"><?= number_format((int)$queueStats['outstanding']) ?></div>
            </div>
            <div class="kpi">
              <div class="kpi-label">In Progress</div>
              <div class="kpi-value"><?= number_format((int)$queueStats['in_progress']) ?></div>
            </div>
            <div class="kpi">
              <div class="kpi-label">Delivered</div>
              <div class="kpi-value"><?= number_format((int)$queueStats['delivered']) ?></div>
            </div>
          </div>
        </div>
      </div>
      <div class="row">
        <div class="card" style="background: rgba(255,255,255,.02);">
          <div class="card-header">
            <h2>View queue</h2>
            <p class="muted">Monitor incoming requests and see status changes in real time.</p>
          </div>
          <div class="card-footer">
            <a class="btn ghost" href="#queue">Open queue</a>
          </div>
        </div>
        <div class="card" style="background: rgba(255,255,255,.02);">
          <div class="card-header">
            <h2>Manage requests</h2>
            <p class="muted">Quote, update, and audit haul requests before posting.</p>
          </div>
          <div class="card-footer">
            <a class="btn ghost" href="#queue">Review requests</a>
          </div>
        </div>
      </div>
      <div class="row" style="margin-top:14px;">
        <div class="card" style="background: rgba(255,255,255,.02);">
          <div class="card-header">
            <h2>Assign haulers</h2>
            <p class="muted">Dispatch internal haulers and track assignments.</p>
          </div>
          <div class="card-footer">
            <a class="btn ghost" href="#queue">Assign jobs</a>
          </div>
        </div>
        <div class="card" style="background: rgba(255,255,255,.02);">
          <div class="card-header">
            <h2>Update status</h2>
            <p class="muted">Mark pickup, in-transit, and delivery milestones.</p>
          </div>
          <div class="card-footer">
            <a class="btn ghost" href="#queue">Update activity</a>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="card" id="queue">
  <div class="card-header">
    <h2>Active requests</h2>
    <p class="muted">Latest hauling requests with assignment status.</p>
  </div>
  <div class="content">
    <?php if (!$isLoggedIn): ?>
      <p class="muted">Sign in to view queue data.</p>
    <?php elseif (!$canViewOps): ?>
      <p class="muted">You do not have permission to view hauling requests.</p>
    <?php elseif (!$requestsAvailable): ?>
      <p class="muted">No hauling requests found yet. Once requests are submitted, they will appear here.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Request</th>
            <th>Route</th>
            <th>Status</th>
            <th>Volume</th>
            <th>Reward</th>
            <th>Requester</th>
            <th>Assigned</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $req): ?>
            <?php
              $fromName = $req['from_name'] ?? $req['from_location_name'] ?? null;
              $toName = $req['to_name'] ?? $req['to_location_name'] ?? null;
              if (!$fromName && !empty($req['from_location_id'])) {
                $fromName = 'Location #' . (string)$req['from_location_id'];
              }
              if (!$toName && !empty($req['to_location_id'])) {
                $toName = 'Location #' . (string)$req['to_location_id'];
              }
            ?>
            <tr>
              <td>#<?= htmlspecialchars((string)$req['request_id'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars(trim((string)$fromName) . ' → ' . trim((string)$toName), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$req['status'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= number_format((float)($req['volume_m3'] ?? 0), 0) ?> m³</td>
              <td><?= number_format((float)($req['reward_isk'] ?? 0), 2) ?> ISK</td>
              <td><?= htmlspecialchars((string)($req['requester_display_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)($req['hauler_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <div class="card-footer">
    <a class="btn ghost" href="<?= htmlspecialchars(($basePath ?: '') . '/', ENT_QUOTES, 'UTF-8') ?>">Back to dashboard</a>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
