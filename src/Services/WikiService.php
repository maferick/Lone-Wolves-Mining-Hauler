<?php
declare(strict_types=1);

namespace App\Services;

final class WikiService
{
  public static function loadPages(string $wikiDir): array
  {
    $pages = [];
    $files = glob(rtrim($wikiDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.md') ?: [];
    foreach ($files as $path) {
      $base = basename($path);
      if (!preg_match('/^(\d{2})_(.+)\.md$/', $base, $matches)) {
        continue;
      }
      $order = (int)$matches[1];
      $name = $matches[2];
      $slug = pathinfo($base, PATHINFO_FILENAME);
      $title = str_replace('_', ' ', $name);
      $pages[] = [
        'slug' => $slug,
        'title' => $title,
        'filename' => $base,
        'path' => $path,
        'order' => $order,
      ];
    }

    usort($pages, static function (array $a, array $b): int {
      if ($a['order'] === $b['order']) {
        return strcmp($a['slug'], $b['slug']);
      }
      return $a['order'] <=> $b['order'];
    });

    return $pages;
  }

  public static function findBySlug(array $pages, string $slug): ?array
  {
    foreach ($pages as $page) {
      if (($page['slug'] ?? '') === $slug) {
        return $page;
      }
    }

    return null;
  }
}
