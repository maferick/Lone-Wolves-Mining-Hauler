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
$isDispatcher = false;
$isAdmin = false;
if ($isLoggedIn) {
  $isDispatcher = \App\Auth\Auth::hasRole($authCtx, 'dispatcher');
  $isAdmin = \App\Auth\Auth::hasRole($authCtx, 'admin');
  foreach ($adminPerms as $permKey) {
    if (\App\Auth\Auth::can($authCtx, $permKey)) {
      $canAdmin = true;
      break;
    }
  }
}
$canRights = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'user.manage');
$canHallOfFame = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.request.read');
$canWiki = $isLoggedIn && (
  \App\Auth\Auth::can($authCtx, 'haul.request.manage')
  || \App\Auth\Auth::can($authCtx, 'haul.execute')
);
$displayName = (string)($authCtx['display_name'] ?? 'Guest');
$corpId = (int)($config['corp']['id'] ?? 0);
$corpLogoUrl = $corpId > 0 ? "https://images.evetech.net/corporations/{$corpId}/logo?size=64" : null;
$appName = htmlspecialchars($appName ?? ($config['app']['name'] ?? 'Corp Hauling'), ENT_QUOTES, 'UTF-8');
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$title = htmlspecialchars($title ?? $appName, ENT_QUOTES, 'UTF-8');
$rawMetaDescription = trim((string)($metaDescription ?? ($config['app']['meta_description'] ?? '')));
if ($rawMetaDescription === '') {
  $rawMetaDescription = sprintf(
    '%s provides secure in-house hauling operations, routing, contracts, and dispatch for corp logistics.',
    $appName
  );
}
$metaDescription = htmlspecialchars($rawMetaDescription, ENT_QUOTES, 'UTF-8');
$body = $body ?? '';
$bodyClass = trim((string)($bodyClass ?? ''));
$branding = $config['branding'] ?? [];
$panelIntensity = (int)($branding['panel_intensity'] ?? 60);
$panelIntensity = max(0, min(100, $panelIntensity));
$panelScale = $panelIntensity / 100;
$cardAlpha = 0.08 + (0.32 * $panelScale);
$transparencyEnabled = !array_key_exists('transparency_enabled', $branding) || !empty($branding['transparency_enabled']);
$bodyStyle = sprintf(
  '--card-alpha: %.3f;',
  $cardAlpha
);
$backgroundImagePath = '/assets/background.jpg';
$backgroundDiskPath = __DIR__ . '/../../public/assets/background.jpg';
if (!file_exists($backgroundDiskPath)) {
  $backgroundImagePath = '/assets/background.png';
  $backgroundDiskPath = __DIR__ . '/../../public/assets/background.png';
}
$backgroundWebpPath = '/assets/background.webp';
$backgroundWebpDiskPath = __DIR__ . '/../../public/assets/background.webp';
if (file_exists($backgroundDiskPath)) {
  $backgroundUrl = ($basePath ?: '') . $backgroundImagePath;
  $bodyStyle .= sprintf(' --brand-bg-image: url(\'%s\');', $backgroundUrl);
  if (file_exists($backgroundWebpDiskPath)) {
    $backgroundWebpUrl = ($basePath ?: '') . $backgroundWebpPath;
    $bodyStyle .= sprintf(
      ' --brand-bg-image-set: image-set(url(\'%s\') type(\'image/webp\'), url(\'%s\') type(\'image/jpeg\'));',
      $backgroundWebpUrl,
      $backgroundUrl
    );
  }
}
$backgroundAllPages = !empty($branding['background_all_pages']);
if ($backgroundAllPages) {
  $bodyClass = trim($bodyClass . ' brand-bg');
}
$requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
$adminPathPrefix = ($basePath !== '' ? $basePath : '') . '/admin/';
$isAdminRoute = str_starts_with($requestUri, $adminPathPrefix);
if ($isAdminRoute) {
  $bodyClass = trim($bodyClass . ' is-admin');
}
$bodyTransparencyAttr = $transparencyEnabled ? '' : ' data-transparency="off"';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="color-scheme" content="dark" />
  <meta name="description" content="<?= $metaDescription ?>" />
  <title><?= $title ?></title>
  <?php if ($corpLogoUrl): ?>
    <link rel="icon" href="<?= htmlspecialchars($corpLogoUrl, ENT_QUOTES, 'UTF-8') ?>" />
  <?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= ($basePath ?: '') ?>/assets/css/app.css?v=2026" />
</head>
<body<?= $bodyClass !== '' ? ' class="' . htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') . '"' : '' ?><?= $bodyTransparencyAttr ?> style="<?= htmlspecialchars($bodyStyle, ENT_QUOTES, 'UTF-8') ?>">
  <div class="app-shell">
    <header class="topbar">
      <div class="brand">
        <?php if ($corpLogoUrl): ?>
          <img class="brand-logo" src="<?= htmlspecialchars($corpLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Corp logo" width="34" height="34" loading="lazy" decoding="async" />
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
          <a class="nav-link" href="<?= ($basePath ?: '') ?>/profile">Profile</a>
          <a class="nav-link" href="<?= ($basePath ?: '') ?>/quote">Quote</a>
        <?php endif; ?>
        <a class="nav-link" href="<?= ($basePath ?: '') ?>/rates">Rates</a>
        <a class="nav-link" href="<?= ($basePath ?: '') ?>/faq">FAQ</a>
        <?php if ($canWiki): ?>
          <a class="nav-link" href="<?= ($basePath ?: '') ?>/wiki/">Wiki</a>
        <?php endif; ?>
        <?php if ($canHallOfFame): ?>
          <a class="nav-link" href="<?= ($basePath ?: '') ?>/hall-of-fame">Hall of Fame</a>
        <?php endif; ?>
      </nav>

      <div class="topbar-actions">
        <?php if ($isLoggedIn): ?>
          <span class="topbar-user">Signed in as <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
          <?php if ($canAdmin || $isDispatcher): ?>
            <a class="btn admin" href="<?= ($basePath ?: '') . (($isDispatcher && !$isAdmin) ? '/admin/users/' : '/admin/') ?>">Admin</a>
          <?php endif; ?>
          <a class="btn ghost" href="<?= ($basePath ?: '') ?>/logout/">Logout</a>
        <?php else: ?>
          <a class="btn ghost" href="<?= ($basePath ?: '') ?>/login/">Login</a>
        <?php endif; ?>
        <span class="pill">2026</span>
      </div>
    </header>

    <main class="main">
      <?= $body ?>
    </main>

    <footer class="footer">
      <div>© <?= date('Y') ?> <?= $appName ?> • Built for corp operations</div>
    </footer>
  </div>
</body>
</html>
