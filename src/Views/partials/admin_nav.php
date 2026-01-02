<?php
declare(strict_types=1);

use App\Auth\Auth;

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$me = $authCtx['display_name'] ?? 'User';
$apiKey = (string)($config['security']['api_key'] ?? '');
$canRights = !empty($authCtx['user_id']) && Auth::can($authCtx, 'user.manage');
?>
<div class="adminbar">
  <div class="adminbar-left">
    <a class="nav-link" href="<?= ($basePath ?: '') ?>/">Dashboard</a>
    <a class="nav-link" href="<?= ($basePath ?: '') ?>/admin/">Admin</a>
    <a class="nav-link" href="<?= ($basePath ?: '') ?>/admin/settings/">Corp</a>
    <a class="nav-link" href="<?= ($basePath ?: '') ?>/admin/defaults/">Defaults</a>
    <a class="nav-link" href="<?= ($basePath ?: '') ?>/admin/hauling/">Hauling</a>
    <a class="nav-link" href="<?= ($basePath ?: '') ?>/admin/users/">Users</a>
    <a class="nav-link" href="<?= ($basePath ?: '') ?>/admin/esi/">ESI</a>
    <a class="nav-link" href="<?= ($basePath ?: '') ?>/admin/cron/">Cron</a>
    <a class="nav-link" href="<?= ($basePath ?: '') ?>/admin/webhooks/">Webhooks</a>
    <a class="nav-link" href="<?= ($basePath ?: '') ?>/api/ping<?= $apiKey !== '' ? '?api_key=' . urlencode($apiKey) : '' ?>">API</a>
    <?php if ($canRights): ?>
      <a class="nav-link" href="<?= ($basePath ?: '') ?>/rights/">Rights</a>
    <?php endif; ?>
  </div>
  <div class="adminbar-right">
    <span class="pill subtle"><?= htmlspecialchars($me, ENT_QUOTES, 'UTF-8') ?></span>
    <a class="btn ghost" href="<?= ($basePath ?: '') ?>/logout/">Logout</a>
  </div>
</div>
