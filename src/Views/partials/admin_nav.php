<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$me = $authCtx['display_name'] ?? 'User';
?>
<div class="adminbar">
  <div class="adminbar-left">
    <a class="nav-link" href="<?= ($basePath ?: '') ?>/">Dashboard</a>
    <a class="nav-link" href="<?= ($basePath ?: '') ?>/admin">Admin</a>
    <a class="nav-link" href="<?= ($basePath ?: '') ?>/admin/settings">Corp</a>
    <a class="nav-link" href="<?= ($basePath ?: '') ?>/admin/users">Users</a>
    <a class="nav-link" href="<?= ($basePath ?: '') ?>/admin/esi">ESI</a>
    <a class="nav-link" href="<?= ($basePath ?: '') ?>/admin/webhooks">Webhooks</a>
  </div>
  <div class="adminbar-right">
    <span class="pill subtle"><?= htmlspecialchars($me, ENT_QUOTES, 'UTF-8') ?></span>
    <a class="btn ghost" href="<?= ($basePath ?: '') ?>/logout">Logout</a>
  </div>
</div>
