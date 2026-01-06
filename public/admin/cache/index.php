<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'esi.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Cache';

$notice = null;
$cacheScopeClause = 'corp_id = :cid OR corp_id IS NULL';
$cacheScopeParams = ['cid' => $corpId];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'clear_cache') {
    $db->execute(
      "DELETE FROM esi_cache WHERE {$cacheScopeClause}",
      $cacheScopeParams
    );
    $notice = 'Cleared cache entries for this corp and shared scope.';
  }
}

$cachePage = max(1, (int)($_GET['page'] ?? 1));
$cachePerPage = 25;
$cacheTotalRow = $db->one(
  "SELECT COUNT(*) AS total
     FROM esi_cache
    WHERE {$cacheScopeClause}",
  $cacheScopeParams
);
$cacheTotal = (int)($cacheTotalRow['total'] ?? 0);
$cacheTotalPages = max(1, (int)ceil($cacheTotal / $cachePerPage));
if ($cachePage > $cacheTotalPages) {
  $cachePage = $cacheTotalPages;
}
$cacheOffset = ($cachePage - 1) * $cachePerPage;

$cacheRows = $db->select(
  "SELECT cache_id, corp_id, http_method, url, status_code, etag, expires_at, fetched_at, ttl_seconds, error_text
     FROM esi_cache
    WHERE {$cacheScopeClause}
    ORDER BY fetched_at DESC, cache_id DESC
    LIMIT :limit OFFSET :offset",
  $cacheScopeParams + [
    'limit' => $cachePerPage,
    'offset' => $cacheOffset,
  ]
);

ob_start();
?>
<section class="grid">
  <div class="card">
    <div class="card-header">
      <h2>ESI Cache</h2>
      <p class="muted">Review cached ESI responses for this corp and shared data.</p>
    </div>
    <div class="content">
      <?php if ($notice): ?>
        <div class="alert alert-success" style="margin-bottom:12px;"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <div class="row" style="align-items:center; justify-content:space-between;">
        <div class="muted">Total cached entries: <?= htmlspecialchars((string)$cacheTotal, ENT_QUOTES, 'UTF-8') ?></div>
        <form method="post">
          <input type="hidden" name="action" value="clear_cache" />
          <button class="btn ghost" type="submit">Clear cache</button>
        </form>
      </div>
      <table class="table" style="margin-top:12px;">
        <thead>
          <tr>
            <th>Scope</th>
            <th>Method</th>
            <th>URL</th>
            <th>Status</th>
            <th>ETag</th>
            <th>Fetched</th>
            <th>Expires</th>
            <th>TTL</th>
            <th>Error</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($cacheRows === []): ?>
            <tr><td colspan="9" class="muted">No cache entries found.</td></tr>
          <?php else: ?>
            <?php foreach ($cacheRows as $row): ?>
              <?php $scopeLabel = $row['corp_id'] === null ? 'Shared' : 'Corp'; ?>
              <tr>
                <td><?= htmlspecialchars($scopeLabel, ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)$row['http_method'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><code><?= htmlspecialchars((string)$row['url'], ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= htmlspecialchars((string)$row['status_code'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($row['etag'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($row['fetched_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($row['expires_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($row['ttl_seconds'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($row['error_text'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
      <?php if ($cacheTotalPages > 1): ?>
        <div class="cron-pagination">
          <?php
            $pageParams = $_GET;
            $pageParams['page'] = max(1, $cachePage - 1);
          ?>
          <a class="btn ghost <?= $cachePage <= 1 ? 'disabled' : '' ?>" href="<?= ($basePath ?: '') ?>/admin/cache/?<?= htmlspecialchars(http_build_query($pageParams), ENT_QUOTES, 'UTF-8') ?>">Previous</a>
          <span class="muted">Page <?= $cachePage ?> of <?= $cacheTotalPages ?></span>
          <?php
            $pageParams['page'] = min($cacheTotalPages, $cachePage + 1);
          ?>
          <a class="btn ghost <?= $cachePage >= $cacheTotalPages ? 'disabled' : '' ?>" href="<?= ($basePath ?: '') ?>/admin/cache/?<?= htmlspecialchars(http_build_query($pageParams), ENT_QUOTES, 'UTF-8') ?>">Next</a>
        </div>
      <?php endif; ?>
      <div style="margin-top:14px;">
        <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/">Back</a>
      </div>
    </div>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/admin_layout.php';
