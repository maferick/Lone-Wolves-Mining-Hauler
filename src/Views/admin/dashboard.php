<?php
declare(strict_types=1);

ob_start();
?>
<section class="card">
  <div class="card-header">
    <h2>Administration</h2>
    <p class="muted">Corp / Alliance / ESI configuration</p>
  </div>
  <div class="content grid">
    <a class="btn" href="<?= ($config['app']['base_path'] ?? '') ?>/admin/esi.php">ESI Settings</a>
    <a class="btn" href="<?= ($config['app']['base_path'] ?? '') ?>/admin/roles.php">Roles & Permissions</a>
    <a class="btn" href="<?= ($config['app']['base_path'] ?? '') ?>/admin/config.php">Config</a>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../admin_layout.php';
