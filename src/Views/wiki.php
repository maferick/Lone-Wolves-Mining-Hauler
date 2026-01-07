<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');

ob_start();
require __DIR__ . '/partials/admin_nav.php';
?>
<section class="wiki-layout">
  <aside class="card wiki-sidebar">
    <div class="card-header">
      <h3>Wiki</h3>
      <p class="muted">Safety procedures &amp; operations guidance.</p>
    </div>
    <div class="content">
      <ul class="wiki-sidebar-list">
        <?php foreach ($wikiPages as $page): ?>
          <?php
            $isActive = ($currentPage['slug'] ?? '') === ($page['slug'] ?? '');
            $pageUrl = ($basePath ?: '') . '/wiki/' . $page['slug'] . '/';
          ?>
          <li>
            <a class="wiki-sidebar-link<?= $isActive ? ' is-active' : '' ?>" href="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars($page['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </aside>

  <div class="wiki-main">
    <section class="card">
      <div class="card-header">
        <div>
          <h2><?= htmlspecialchars($currentPage['title'] ?? 'Wiki', ENT_QUOTES, 'UTF-8') ?></h2>
          <?php if (!empty($lastUpdatedLabel)): ?>
            <p class="muted">Last updated <?= htmlspecialchars($lastUpdatedLabel, ENT_QUOTES, 'UTF-8') ?></p>
          <?php endif; ?>
        </div>
      </div>
      <div class="content">
        <?php if (!empty($toc)): ?>
          <div class="wiki-toc">
            <div class="label">On this page</div>
            <ul class="wiki-toc-list">
              <?php foreach ($toc as $item): ?>
                <?php $tocIndent = (int)($item['level'] ?? 2) === 3 ? ' is-indent' : ''; ?>
                <li class="wiki-toc-item<?= $tocIndent ?>">
                  <a href="#<?= htmlspecialchars($item['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($item['text'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
        <?= $wikiHtml ?>
      </div>
    </section>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
