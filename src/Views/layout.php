<?php
declare(strict_types=1);

/**
 * src/Views/layout.php
 * Dark, modern 2026 look & feel.
 */
$appName = htmlspecialchars($appName ?? 'Corp Hauling', ENT_QUOTES, 'UTF-8');
$title = htmlspecialchars($title ?? $appName, ENT_QUOTES, 'UTF-8');
$body = $body ?? '';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="color-scheme" content="dark" />
  <title><?= $title ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/app.css?v=2026" />
</head>
<body>
  <div class="app-shell">
    <header class="topbar">
      <div class="brand">
        <div class="brand-dot"></div>
        <div class="brand-text">
          <div class="brand-name"><?= $appName ?></div>
          <div class="brand-sub">In-house logistics • routing • contracts • dispatch</div>
        </div>
      </div>

      <nav class="nav">
        <a class="nav-link" href="/">Dashboard</a>
        <a class="nav-link" href="/health">Health</a>
        <a class="nav-link" href="/docs">Docs</a>
      </nav>

      <div class="topbar-actions">
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
