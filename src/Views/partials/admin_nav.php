<?php
declare(strict_types=1);

use App\Auth\Auth;

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$me = $authCtx['display_name'] ?? 'User';
$canRights = !empty($authCtx['user_id']) && Auth::can($authCtx, 'user.manage');
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
if (!empty($authCtx['user_id'])) {
  foreach ($adminPerms as $permKey) {
    if (Auth::can($authCtx, $permKey)) {
      $hasAnyAdmin = true;
      break;
    }
  }
}
$navItems = [
  ['label' => 'Admin', 'path' => '/admin/', 'perm' => null],
  ['label' => 'Corp', 'path' => '/admin/settings/', 'perm' => 'corp.manage'],
  ['label' => 'Access', 'path' => '/admin/access/', 'perm' => 'corp.manage'],
  ['label' => 'Defaults', 'path' => '/admin/defaults/', 'perm' => 'pricing.manage'],
  ['label' => 'Hauling', 'path' => '/admin/hauling/', 'perm' => 'haul.request.manage'],
  ['label' => 'Pricing', 'path' => '/admin/pricing/', 'perm' => 'pricing.manage'],
  ['label' => 'Users', 'path' => '/admin/users/', 'perm' => 'user.manage'],
  ['label' => 'ESI', 'path' => '/admin/esi/', 'perm' => 'esi.manage'],
  ['label' => 'Cache', 'path' => '/admin/cache/', 'perm' => 'esi.manage'],
  ['label' => 'Cron', 'path' => '/admin/cron/', 'perm' => 'esi.manage'],
  ['label' => 'Webhooks', 'path' => '/admin/webhooks/', 'perm' => 'webhook.manage'],
  ['label' => 'Discord', 'path' => '/admin/discord/', 'perm' => 'webhook.manage'],
];
?>
<div class="adminbar">
  <div class="adminbar-left">
    <a class="nav-link" href="<?= ($basePath ?: '') ?>/">Dashboard</a>
    <?php foreach ($navItems as $item): ?>
      <?php if (($item['perm'] === null && $hasAnyAdmin) || ($item['perm'] !== null && Auth::can($authCtx, $item['perm']))): ?>
        <a class="nav-link" href="<?= ($basePath ?: '') . $item['path'] ?>"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
    <a class="nav-link" href="<?= ($basePath ?: '') ?>/api/ping">API</a>
    <?php if ($canRights): ?>
      <a class="nav-link" href="<?= ($basePath ?: '') ?>/rights/">Rights</a>
    <?php endif; ?>
  </div>
  <div class="adminbar-right">
    <span class="pill subtle"><?= htmlspecialchars($me, ENT_QUOTES, 'UTF-8') ?></span>
    <a class="btn ghost" href="<?= ($basePath ?: '') ?>/logout/">Logout</a>
  </div>
</div>
