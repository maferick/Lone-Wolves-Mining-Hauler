<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$docTitle = $docTitle ?? 'Document';
$docDescription = $docDescription ?? '';
$docPath = $docPath ?? '';

$markdown = '';
if ($docPath !== '' && file_exists($docPath)) {
  $contents = file_get_contents($docPath);
  if ($contents !== false) {
    $markdown = $contents;
  }
}

if ($markdown === '') {
  $markdown = "# Document unavailable\n\nThe requested document could not be loaded.";
}

$renderInline = static function (string $text): string {
  $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
  return preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped;
};

$renderMarkdown = static function (string $markdown) use ($renderInline): string {
  $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
  $html = '';
  $paragraph = '';
  $inCode = false;
  $codeBuffer = [];
  $listType = null;

  $flushParagraph = static function () use (&$paragraph, &$html, $renderInline): void {
    if (trim($paragraph) !== '') {
      $html .= '<p>' . $renderInline(trim($paragraph)) . '</p>';
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
        $html .= '<pre><code>' . $codeText . '</code></pre>';
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
      $html .= sprintf('<h%d>%s</h%d>', $level, $renderInline($matches[2]), $level);
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
      $html .= '<li>' . $renderInline($matches[1]) . '</li>';
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
      $html .= '<li>' . $renderInline($matches[1]) . '</li>';
      continue;
    }

    $paragraph .= ($paragraph === '' ? '' : ' ') . trim($trimmed);
  }

  if ($inCode) {
    $codeText = htmlspecialchars(implode("\n", $codeBuffer), ENT_QUOTES, 'UTF-8');
    $html .= '<pre><code>' . $codeText . '</code></pre>';
  }

  $flushParagraph();
  $closeList();

  return $html;
};

$bodyContent = $renderMarkdown($markdown);

ob_start();
?>
<section class="card">
  <div class="card-header">
    <h2><?= htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8') ?></h2>
    <?php if ($docDescription !== ''): ?>
      <p class="muted"><?= htmlspecialchars($docDescription, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
  </div>
  <div class="content">
    <?= $bodyContent ?>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
