<?php
declare(strict_types=1);

use App\Services\MarkdownService;

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$docTitle = $docTitle ?? 'Document';
$docDescription = $docDescription ?? '';
$docPath = $docPath ?? '';

$markdown = '';
if ($docPath !== '' && file_exists($docPath)) {
  $contents = file_get_contents($docPath);
  if ($contents !== false) {
    $markdown = $contents;
  }
}

if ($markdown === '') {
  $markdown = "# Document unavailable\n\nThe requested document could not be loaded.";
}

$rendered = MarkdownService::render($markdown);
$bodyContent = $rendered['html'];

ob_start();
?>
<section class="card">
  <div class="card-header">
    <h2><?= htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8') ?></h2>
    <?php if ($docDescription !== ''): ?>
      <p class="muted"><?= htmlspecialchars($docDescription, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
  </div>
  <div class="content">
    <?= $bodyContent ?>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
