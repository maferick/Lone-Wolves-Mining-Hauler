<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use App\Auth\Auth;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
$adminPerms = [
  'corp.manage',
  'esi.manage',
  'webhook.manage',
  'pricing.manage',
  'user.manage',
  'haul.request.manage',
  'haul.assign',
];
$hasAnyAdmin = false;
foreach ($adminPerms as $permKey) {
  if (Auth::can($authCtx, $permKey)) {
    $hasAnyAdmin = true;
    break;
  }
}
if (!$hasAnyAdmin) {
  http_response_code(403);
  echo "Forbidden";
  exit;
}

$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Admin';
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');

ob_start();
require __DIR__ . '/../../src/Views/partials/admin_nav.php';
?>
<section class="grid">
  <div class="card">
    <div class="card-header">
      <h2>Admin Control Plane</h2>
      <p class="muted">Configuration is centralized, audited, and ESI-cached.</p>
    </div>
    <div class="content">
      <div class="row">
        <?php if (Auth::can($authCtx, 'corp.manage')): ?>
          <div>
            <div class="label">Corp</div>
            <a class="btn" href="<?= ($basePath ?: '') ?>/admin/settings/">Manage corp & alliance</a>
          </div>
          <div>
            <div class="label">Access</div>
            <a class="btn" href="<?= ($basePath ?: '') ?>/admin/access/">Login access rules</a>
          </div>
        <?php endif; ?>
        <?php if (Auth::can($authCtx, 'pricing.manage')): ?>
          <div>
            <div class="label">Defaults</div>
            <a class="btn" href="<?= ($basePath ?: '') ?>/admin/defaults/">Pricing & routing</a>
          </div>
          <div>
            <div class="label">Pricing</div>
            <a class="btn" href="<?= ($basePath ?: '') ?>/admin/pricing/">Economic controls</a>
          </div>
        <?php endif; ?>
        <?php if (Auth::can($authCtx, 'haul.request.manage')): ?>
          <div>
            <div class="label">Hauling</div>
            <a class="btn" href="<?= ($basePath ?: '') ?>/admin/hauling/">Risk & routing</a>
          </div>
        <?php endif; ?>
        <?php if (Auth::can($authCtx, 'user.manage')): ?>
          <div>
            <div class="label">Users</div>
            <a class="btn" href="<?= ($basePath ?: '') ?>/admin/users/">Roles & sub-admins</a>
          </div>
        <?php endif; ?>
        <?php if (Auth::can($authCtx, 'esi.manage')): ?>
          <div>
            <div class="label">ESI</div>
            <a class="btn" href="<?= ($basePath ?: '') ?>/admin/esi/">Tokens & contract sync</a>
          </div>
          <div>
            <div class="label">Cron</div>
            <a class="btn" href="<?= ($basePath ?: '') ?>/admin/cron/">Scheduler command</a>
          </div>
        <?php endif; ?>
        <?php if (Auth::can($authCtx, 'webhook.manage')): ?>
          <div>
            <div class="label">Webhooks</div>
            <a class="btn" href="<?= ($basePath ?: '') ?>/admin/webhooks/">Discord webhooks</a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <h2>Operational status</h2>
      <p class="muted">Fast indicators sourced from the health contract.</p>
    </div>
    <ul class="list">
      <li><span class="badge">DB</span> <?= ($health['db'] ?? false) ? 'Online' : 'Offline' ?></li>
      <li><span class="badge">ENV</span> <?= htmlspecialchars($health['env'] ?? 'dev') ?></li>
      <li><span class="badge">UTC</span> <?= htmlspecialchars($health['time_utc'] ?? gmdate('c')) ?></li>
    </ul>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../src/Views/layout.php';
