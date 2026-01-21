<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Cache\CacheMetricsRepository;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requireAdmin($authCtx);

$corpId = (int)($authCtx['corp_id'] ?? 0);
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Cache';

$notice = null;
$cacheScopeClauseDefault = '(corp_id = :cid OR corp_id IS NULL)';
$cacheScopeParamsDefault = ['cid' => $corpId];

$metricsEnabled = (bool)($config['cache']['metrics']['enabled'] ?? true);
$metricsPayload = $metricsEnabled ? CacheMetricsRepository::getCurrent($db) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'clear_cache') {
    $db->execute(
      "DELETE FROM esi_cache WHERE {$cacheScopeClauseDefault}",
      $cacheScopeParamsDefault
    );
    $notice = 'Cleared cache entries for this corp and shared scope.';
  }
  if ($action === 'reset_metrics' && $metricsEnabled) {
    CacheMetricsRepository::reset($db);
    $metricsPayload = CacheMetricsRepository::getCurrent($db);
    $notice = 'Cache metrics reset.';
  }
}

$filters = [
  'corp_id' => trim((string)($_GET['corp_id'] ?? '')),
  'key_prefix' => strtoupper(preg_replace('/[^0-9a-f]/i', '', (string)($_GET['key_prefix'] ?? ''))),
  'source' => trim((string)($_GET['source'] ?? '')),
  'group' => strtoupper(trim((string)($_GET['group'] ?? ''))),
  'age' => (int)($_GET['age'] ?? 0),
  'per_page' => (int)($_GET['per_page'] ?? 200),
];

$filters['per_page'] = max(1, min(500, $filters['per_page']));
$filters['age'] = max(0, $filters['age']);

$where = [];
$params = [];

if ($filters['corp_id'] === '') {
  $where[] = $cacheScopeClauseDefault;
  $params += $cacheScopeParamsDefault;
} elseif (strtolower($filters['corp_id']) === 'shared') {
  $where[] = 'corp_id IS NULL';
} elseif (is_numeric($filters['corp_id'])) {
  $where[] = 'corp_id = :corp_id_filter';
  $params['corp_id_filter'] = (int)$filters['corp_id'];
} else {
  $where[] = $cacheScopeClauseDefault;
  $params += $cacheScopeParamsDefault;
}

if ($filters['key_prefix'] !== '') {
  $where[] = 'HEX(cache_key) LIKE :cache_key_prefix';
  $params['cache_key_prefix'] = $filters['key_prefix'] . '%';
}

if ($filters['source'] !== '') {
  $where[] = 'url LIKE :url_prefix';
  $params['url_prefix'] = $filters['source'] . '%';
}

if ($filters['group'] !== '') {
  $where[] = 'http_method = :http_method';
  $params['http_method'] = $filters['group'];
}

if ($filters['age'] > 0) {
  $cutoff = gmdate('Y-m-d H:i:s', time() - ($filters['age'] * 60));
  $where[] = 'fetched_at >= :fetched_after';
  $params['fetched_after'] = $cutoff;
}

$whereClause = $where !== [] ? implode(' AND ', $where) : '1=1';

$cachePage = max(1, (int)($_GET['page'] ?? 1));
$cachePerPage = $filters['per_page'];
$cacheTotalRow = $db->one(
  "SELECT COUNT(*) AS total
     FROM esi_cache
    WHERE {$whereClause}",
  $params
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
    WHERE {$whereClause}
    ORDER BY fetched_at DESC, cache_id DESC
    LIMIT :limit OFFSET :offset",
  $params + [
    'limit' => $cachePerPage,
    'offset' => $cacheOffset,
  ]
);

$totalGets = (int)($metricsPayload['cache_get_total'] ?? 0);
$totalSets = (int)($metricsPayload['cache_set_total'] ?? 0);
$redisHits = (int)($metricsPayload['cache_get_hit_redis'] ?? 0);
$dbHits = (int)($metricsPayload['cache_get_hit_db'] ?? 0);
$redisFails = (int)($metricsPayload['cache_set_redis_fail'] ?? 0);
$dbTimeTotal = (float)($metricsPayload['cache_get_db_time_total_ms'] ?? 0.0);
$dbTimeCount = (int)($metricsPayload['cache_get_db_time_count'] ?? 0);
$redisHitRate = $totalGets > 0 ? ($redisHits / $totalGets) * 100 : 0.0;
$dbHitRate = $totalGets > 0 ? ($dbHits / $totalGets) * 100 : 0.0;
$redisFailRate = $totalSets > 0 ? ($redisFails / $totalSets) * 100 : 0.0;
$dbAvgMs = $dbTimeCount > 0 ? $dbTimeTotal / $dbTimeCount : 0.0;
$metricsUpdatedAt = $metricsPayload['updated_at'] ?? null;

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="grid">
  <div class="card">
    <div class="card-header">
      <h2>Cache Performance</h2>
      <p class="muted">Rolling cache metrics across Redis and MariaDB.</p>
    </div>
    <div class="content">
      <?php if (!$metricsEnabled): ?>
        <div class="muted">Metrics collection is disabled.</div>
      <?php else: ?>
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
          <div class="card" style="padding:12px;">
            <div class="muted">Redis hit rate</div>
            <div><strong><?= htmlspecialchars(number_format($redisHitRate, 1), ENT_QUOTES, 'UTF-8') ?>%</strong></div>
          </div>
          <div class="card" style="padding:12px;">
            <div class="muted">DB hit rate</div>
            <div><strong><?= htmlspecialchars(number_format($dbHitRate, 1), ENT_QUOTES, 'UTF-8') ?>%</strong></div>
          </div>
          <div class="card" style="padding:12px;">
            <div class="muted">Redis write failures</div>
            <div><strong><?= htmlspecialchars((string)$redisFails, ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div class="muted" style="font-size:0.85em;"><?= htmlspecialchars(number_format($redisFailRate, 1), ENT_QUOTES, 'UTF-8') ?>% of sets</div>
          </div>
          <div class="card" style="padding:12px;">
            <div class="muted">Avg DB cache-get</div>
            <div><strong><?= htmlspecialchars(number_format($dbAvgMs, 2), ENT_QUOTES, 'UTF-8') ?> ms</strong></div>
          </div>
          <div class="card" style="padding:12px;">
            <div class="muted">Last update</div>
            <div><strong><?= htmlspecialchars((string)($metricsUpdatedAt ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong></div>
          </div>
        </div>
        <div style="margin-top:12px; display:flex; gap:8px; align-items:center;">
          <form method="post">
            <input type="hidden" name="action" value="reset_metrics" />
            <button class="btn ghost" type="submit">Reset metrics</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <h2>ESI Cache</h2>
      <p class="muted">Review cached ESI responses for this corp and shared data.</p>
    </div>
    <div class="content">
      <?php if ($notice): ?>
        <div class="alert alert-success" style="margin-bottom:12px;"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="get" class="row" style="gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div>
          <label class="muted" for="corp_id">Corp ID</label><br />
          <input id="corp_id" name="corp_id" type="text" value="<?= htmlspecialchars($filters['corp_id'], ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars((string)$corpId, ENT_QUOTES, 'UTF-8') ?>" />
        </div>
        <div>
          <label class="muted" for="key_prefix">Key prefix (hex)</label><br />
          <input id="key_prefix" name="key_prefix" type="text" value="<?= htmlspecialchars($filters['key_prefix'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. ABC123" />
        </div>
        <div>
          <label class="muted" for="source">Source/URL prefix</label><br />
          <input id="source" name="source" type="text" value="<?= htmlspecialchars($filters['source'], ENT_QUOTES, 'UTF-8') ?>" placeholder="https://esi.evetech.net" />
        </div>
        <div>
          <label class="muted" for="group">Group/Method</label><br />
          <input id="group" name="group" type="text" value="<?= htmlspecialchars($filters['group'], ENT_QUOTES, 'UTF-8') ?>" placeholder="GET" />
        </div>
        <div>
          <label class="muted" for="age">Max age (minutes)</label><br />
          <input id="age" name="age" type="number" min="0" value="<?= htmlspecialchars((string)$filters['age'], ENT_QUOTES, 'UTF-8') ?>" />
        </div>
        <div>
          <label class="muted" for="per_page">Per page</label><br />
          <input id="per_page" name="per_page" type="number" min="1" max="500" value="<?= htmlspecialchars((string)$filters['per_page'], ENT_QUOTES, 'UTF-8') ?>" />
        </div>
        <div>
          <button class="btn" type="submit">Apply filters</button>
          <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/cache/">Reset</a>
        </div>
      </form>

      <div class="row" style="align-items:center; justify-content:space-between; margin-top:12px;">
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
require __DIR__ . '/../../../src/Views/layout.php';
