<?php
declare(strict_types=1);

namespace App\Services;

final class MarkdownService
{
  public static function render(string $markdown, array $options = []): array
  {
    $tocLevels = array_map('intval', $options['toc_levels'] ?? []);
    $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
    $html = '';
    $paragraph = '';
    $inCode = false;
    $codeBuffer = [];
    $listType = null;
    $toc = [];
    $headingCounts = [];

    $flushParagraph = static function () use (&$paragraph, &$html): void {
      if (trim($paragraph) !== '') {
        $html .= '<p>' . self::renderInline($paragraph) . '</p>';
        $paragraph = '';
      }
    };

    $closeList = static function () use (&$listType, &$html): void {
      if ($listType !== null) {
        $html .= $listType === 'ol' ? '</ol>' : '</ul>';
        $listType = null;
      }
    };

    foreach ($lines as $line) {
      $trimmed = rtrim($line);

      if (str_starts_with(trim($trimmed), '```')) {
        if ($inCode) {
          $codeText = htmlspecialchars(implode("\n", $codeBuffer), ENT_QUOTES, 'UTF-8');
          $html .= '<pre class="cron-log"><code>' . $codeText . '</code></pre>';
          $codeBuffer = [];
          $inCode = false;
        } else {
          $flushParagraph();
          $closeList();
          $inCode = true;
        }
        continue;
      }

      if ($inCode) {
        $codeBuffer[] = $trimmed;
        continue;
      }

      if (trim($trimmed) === '') {
        $flushParagraph();
        $closeList();
        continue;
      }

      if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $matches)) {
        $flushParagraph();
        $closeList();
        $level = strlen($matches[1]);
        $headingText = trim($matches[2]);
        $anchor = self::slugify($headingText, $headingCounts);
        if (in_array($level, $tocLevels, true)) {
          $toc[] = [
            'level' => $level,
            'id' => $anchor,
            'text' => $headingText,
          ];
        }
        $html .= sprintf(
          '<h%d id="%s">%s</h%d>',
          $level,
          htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8'),
          self::renderInline($headingText),
          $level
        );
        continue;
      }

      if (preg_match('/^\d+\.\s+(.*)$/', $trimmed, $matches)) {
        $flushParagraph();
        if ($listType === null) {
          $listType = 'ol';
          $html .= '<ol class="list">';
        } elseif ($listType !== 'ol') {
          $closeList();
          $listType = 'ol';
          $html .= '<ol class="list">';
        }
        $html .= '<li>' . self::renderInline($matches[1]) . '</li>';
        continue;
      }

      if (preg_match('/^[-*+]\s+(.*)$/', $trimmed, $matches)) {
        $flushParagraph();
        if ($listType === null) {
          $listType = 'ul';
          $html .= '<ul class="list">';
        } elseif ($listType !== 'ul') {
          $closeList();
          $listType = 'ul';
          $html .= '<ul class="list">';
        }
        $html .= '<li>' . self::renderInline($matches[1]) . '</li>';
        continue;
      }

      $paragraph .= ($paragraph === '' ? '' : ' ') . trim($trimmed);
    }

    if ($inCode) {
      $codeText = htmlspecialchars(implode("\n", $codeBuffer), ENT_QUOTES, 'UTF-8');
      $html .= '<pre class="cron-log"><code>' . $codeText . '</code></pre>';
    }

    $flushParagraph();
    $closeList();

    return [
      'html' => $html,
      'toc' => $toc,
    ];
  }

  private static function renderInline(string $text): string
  {
    $parts = preg_split('/(`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
      return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    $output = '';
    $inCode = false;
    foreach ($parts as $part) {
      if ($part === '`') {
        $inCode = !$inCode;
        continue;
      }
      if ($inCode) {
        $output .= '<code>' . htmlspecialchars($part, ENT_QUOTES, 'UTF-8') . '</code>';
      } else {
        $output .= self::renderInlineText($part);
      }
    }

    return $output;
  }

  private static function renderInlineText(string $text): string
  {
    $output = '';
    $offset = 0;
    if (preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $text, $matches, PREG_OFFSET_CAPTURE)) {
      foreach ($matches[0] as $index => $match) {
        $matchStart = $match[1];
        $matchLength = strlen($match[0]);
        $before = substr($text, $offset, $matchStart - $offset);
        $output .= self::renderEmphasis($before);

        $label = $matches[1][$index][0];
        $url = $matches[2][$index][0];
        $safeUrl = self::sanitizeLink($url);
        if ($safeUrl === null) {
          $output .= htmlspecialchars($match[0], ENT_QUOTES, 'UTF-8');
        } else {
          $output .= sprintf(
            '<a href="%s" rel="noreferrer">%s</a>',
            htmlspecialchars($safeUrl, ENT_QUOTES, 'UTF-8'),
            self::renderEmphasis($label)
          );
        }

        $offset = $matchStart + $matchLength;
      }
    }

    if ($offset < strlen($text)) {
      $output .= self::renderEmphasis(substr($text, $offset));
    }

    return $output;
  }

  private static function renderEmphasis(string $text): string
  {
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped) ?? $escaped;
    $escaped = preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $escaped) ?? $escaped;
    $escaped = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $escaped) ?? $escaped;
    $escaped = preg_replace('/_(.+?)_/s', '<em>$1</em>', $escaped) ?? $escaped;

    return $escaped;
  }

  private static function sanitizeLink(string $url): ?string
  {
    $trimmed = trim($url);
    if ($trimmed === '') {
      return null;
    }

    if (preg_match('#^(https?://|mailto:|/|\\#)#i', $trimmed)) {
      return $trimmed;
    }

    return null;
  }

  private static function slugify(string $text, array &$counts): string
  {
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug) ?? '';
    $slug = preg_replace('/\s+/', '-', $slug) ?? $slug;
    $slug = trim($slug, '-');
    if ($slug === '') {
      $slug = 'section';
    }

    $counts[$slug] = ($counts[$slug] ?? 0) + 1;
    if ($counts[$slug] > 1) {
      $slug .= '-' . $counts[$slug];
    }

    return $slug;
  }
}
