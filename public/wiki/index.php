<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use App\Auth\Auth;
use App\Services\MarkdownService;
use App\Services\WikiService;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requireAccess($authCtx, 'wiki');

$hasWikiAccess = Auth::can($authCtx, 'haul.request.manage')
  || Auth::can($authCtx, 'haul.execute');
if (!$hasWikiAccess) {
  http_response_code(403);
  $appName = $config['app']['name'] ?? 'Corp Hauling';
  $title = $appName . ' • Wiki';
  $errorTitle = 'Not allowed';
  $errorDescription = 'Wiki access is limited to hauling admins and operators.';
  $errorMessage = 'You do not have permission to view the wiki.';
  require __DIR__ . '/../../src/Views/error.php';
  exit;
}

$wikiDir = __DIR__ . '/../../docs/wiki';
$wikiPages = WikiService::loadPages($wikiDir);
$canViewAdminPricing = Auth::hasRole($authCtx, 'admin') || Auth::hasRole($authCtx, 'subadmin');
if (!$canViewAdminPricing) {
  $wikiPages = array_values(array_filter($wikiPages, static function (array $page): bool {
    return ($page['slug'] ?? '') !== '04_Admin_Operations_Pricing';
  }));
}

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$requestPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
if ($basePath !== '' && str_starts_with($requestPath, $basePath)) {
  $requestPath = substr($requestPath, strlen($basePath)) ?: '/';
}

$slug = '';
if (!empty($_GET['slug'])) {
  $slug = (string)$_GET['slug'];
} elseif (preg_match('#^/wiki/([^/]+)/?#', $requestPath, $matches)) {
  $slug = (string)$matches[1];
}

if ($slug === '') {
  $slug = $wikiPages[0]['slug'] ?? '00_INDEX';
}

if (!preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
  http_response_code(404);
  $appName = $config['app']['name'] ?? 'Corp Hauling';
  $title = $appName . ' • Wiki';
  $errorTitle = 'Not found';
  $errorMessage = 'The requested wiki page could not be found.';
  require __DIR__ . '/../../src/Views/error.php';
  exit;
}

if ($slug === '04_Admin_Operations_Pricing' && !$canViewAdminPricing) {
  http_response_code(403);
  $appName = $config['app']['name'] ?? 'Corp Hauling';
  $title = $appName . ' • Wiki';
  $errorTitle = 'Not allowed';
  $errorDescription = 'This page is limited to admins and sub-admins.';
  $errorMessage = 'You do not have permission to view this wiki page.';
  require __DIR__ . '/../../src/Views/error.php';
  exit;
}

$currentPage = WikiService::findBySlug($wikiPages, $slug);
if ($currentPage === null) {
  http_response_code(404);
  $appName = $config['app']['name'] ?? 'Corp Hauling';
  $title = $appName . ' • Wiki';
  $errorTitle = 'Not found';
  $errorMessage = 'The requested wiki page could not be found.';
  require __DIR__ . '/../../src/Views/error.php';
  exit;
}

$markdown = '';
if (!empty($currentPage['path']) && is_file($currentPage['path'])) {
  $contents = file_get_contents($currentPage['path']);
  if ($contents !== false) {
    $markdown = $contents;
  }
}

$rendered = MarkdownService::render($markdown, ['toc_levels' => [2, 3]]);
$wikiHtml = $rendered['html'];
$toc = $rendered['toc'];
$lastUpdatedLabel = '';
if (!empty($currentPage['path']) && is_file($currentPage['path'])) {
  $mtime = filemtime($currentPage['path']);
  if ($mtime !== false) {
    $lastUpdatedLabel = date('M j, Y g:i A', $mtime);
  }
}

$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Wiki • ' . ($currentPage['title'] ?? 'Wiki');

require __DIR__ . '/../../src/Views/wiki.php';
