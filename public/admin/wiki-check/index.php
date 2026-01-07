<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Services\WikiService;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
if (!Auth::hasRole($authCtx, 'admin')) {
  http_response_code(403);
  $appName = $config['app']['name'] ?? 'Corp Hauling';
  $title = $appName . ' • Wiki Check';
  $errorTitle = 'Not allowed';
  $errorMessage = 'You do not have permission to view this diagnostic page.';
  require __DIR__ . '/../../../src/Views/error.php';
  exit;
}

$wikiDir = __DIR__ . '/../../../docs/wiki';
$wikiPages = WikiService::loadPages($wikiDir);

$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Wiki Check';

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="card">
  <div class="card-header">
    <h2>Wiki Registry Check</h2>
    <p class="muted">Detected markdown files in docs/wiki.</p>
  </div>
  <div class="content">
    <?php if ($wikiPages === []): ?>
      <p class="muted">No wiki pages detected.</p>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($wikiPages as $page): ?>
          <li>
            <span class="badge"><?= htmlspecialchars((string)($page['order'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
            <?= htmlspecialchars($page['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            <span class="muted">(<?= htmlspecialchars($page['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?>)</span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
