<?php
declare(strict_types=1);

ob_start();
?>
<section class="card">
  <div class="card-header">
    <h2>ESI / SSO Configuration</h2>
    <p class="muted">Authorize corp access and set owning corp/alliance</p>
  </div>
  <div class="content">
    <p>Current Corp: <strong><?= htmlspecialchars($config['corp']['name'] ?? 'Not set') ?></strong></p>
    <p>Alliance: <strong><?= htmlspecialchars($config['corp']['alliance_name'] ?? 'N/A') ?></strong></p>

    <a class="btn primary" href="<?= ($config['app']['base_path'] ?? '') ?>/sso/login.php">
      Authorize with EVE SSO
    </a>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../admin_layout.php';
