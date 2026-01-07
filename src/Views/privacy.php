<?php
declare(strict_types=1);

use App\Services\MarkdownService;

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$privacyPath = __DIR__ . '/../../docs/PRIVACY.md';
$privacyMarkdown = is_file($privacyPath) ? (string)file_get_contents($privacyPath) : '';

$rendered = MarkdownService::render($privacyMarkdown);
$privacyHtml = $rendered['html'];

ob_start();
?>
<section class="card">
  <div class="card-header">
    <h2>Privacy Policy</h2>
    <p class="muted">Public disclosure for Discord compliance.</p>
  </div>

  <div class="content">
    <?= $privacyHtml ?>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
