<?php
declare(strict_types=1);

/**
 * src/Views/layout.php
 * Dark, modern 2026 look & feel.
 */
$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);
$isLoggedIn = !empty($authCtx['user_id']);
$adminPerms = [
  'corp.manage',
  'esi.manage',
  'webhook.manage',
  'pricing.manage',
  'user.manage',
  'haul.request.manage',
  'haul.assign',
];
$canAdmin = false;
if ($isLoggedIn) {
  foreach ($adminPerms as $permKey) {
    if (\App\Auth\Auth::can($authCtx, $permKey)) {
      $canAdmin = true;
      break;
    }
  }
}
$canRights = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'user.manage');
$canHallOfFame = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.request.read');
$displayName = (string)($authCtx['display_name'] ?? 'Guest');
$corpId = (int)($config['corp']['id'] ?? 0);
$corpLogoUrl = $corpId > 0 ? "https://images.evetech.net/corporations/{$corpId}/logo?size=64" : null;
$appName = htmlspecialchars($appName ?? ($config['app']['name'] ?? 'Corp Hauling'), ENT_QUOTES, 'UTF-8');
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$title = htmlspecialchars($title ?? $appName, ENT_QUOTES, 'UTF-8');
$body = $body ?? '';
$bodyClass = trim((string)($bodyClass ?? ''));
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="color-scheme" content="dark" />
  <title><?= $title ?></title>
  <?php if ($corpLogoUrl): ?>
    <link rel="icon" href="<?= htmlspecialchars($corpLogoUrl, ENT_QUOTES, 'UTF-8') ?>" />
  <?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= ($basePath ?: '') ?>/assets/css/app.css?v=2026" />
</head>
<body<?= $bodyClass !== '' ? ' class="' . htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
  <div class="app-shell">
    <header class="topbar">
      <div class="brand">
        <?php if ($corpLogoUrl): ?>
          <img class="brand-logo" src="<?= htmlspecialchars($corpLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Corp logo" />
        <?php else: ?>
          <div class="brand-dot"></div>
        <?php endif; ?>
        <div class="brand-text">
          <div class="brand-name"><?= $appName ?></div>
          <div class="brand-sub">In-house logistics • routing • contracts • dispatch</div>
        </div>
      </div>

      <nav class="nav">
        <a class="nav-link" href="<?= ($basePath ?: '') ?>/">Home</a>
        <a class="nav-link" href="<?= ($basePath ?: '') ?>/operations">Operations</a>
        <?php if ($isLoggedIn): ?>
          <a class="nav-link" href="<?= ($basePath ?: '') ?>/my-contracts">My Contracts</a>
          <a class="nav-link" href="<?= ($basePath ?: '') ?>/#quote">Quote</a>
        <?php endif; ?>
        <a class="nav-link" href="<?= ($basePath ?: '') ?>/rates">Rates</a>
        <a class="nav-link" href="<?= ($basePath ?: '') ?>/faq">FAQ</a>
        <?php if ($canHallOfFame): ?>
          <a class="nav-link" href="<?= ($basePath ?: '') ?>/hall-of-fame">Hall of Fame</a>
        <?php endif; ?>
      </nav>

      <div class="topbar-actions">
        <?php if ($isLoggedIn): ?>
          <span class="topbar-user">Signed in as <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
          <?php if ($canAdmin): ?>
            <a class="btn admin" href="<?= ($basePath ?: '') ?>/admin/">Admin</a>
          <?php endif; ?>
          <a class="btn ghost" href="<?= ($basePath ?: '') ?>/logout/">Logout</a>
        <?php else: ?>
          <a class="btn ghost" href="<?= ($basePath ?: '') ?>/login/">Login</a>
        <?php endif; ?>
        <span class="pill">2026</span>
        <span class="pill subtle">Dark Ops</span>
      </div>
    </header>

    <main class="main">
      <?= $body ?>
    </main>

    <footer class="footer">
      <div>© <?= date('Y') ?> <?= $appName ?> • Built for corp operations</div>
      <div class="muted">Performance-first • Cache-first • Name-first</div>
    </footer>
  </div>
</body>
</html>
