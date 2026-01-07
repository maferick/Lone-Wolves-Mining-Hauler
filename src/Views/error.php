<?php
declare(strict_types=1);

$errorTitle = $errorTitle ?? 'Access denied';
$errorDescription = $errorDescription ?? '';
$errorMessage = $errorMessage ?? 'You do not have permission to view this page.';

ob_start();
?>
<section class="card">
  <div class="card-header">
    <h2><?= htmlspecialchars($errorTitle, ENT_QUOTES, 'UTF-8') ?></h2>
    <?php if ($errorDescription !== ''): ?>
      <p class="muted"><?= htmlspecialchars($errorDescription, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
  </div>
  <div class="content">
    <p><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
