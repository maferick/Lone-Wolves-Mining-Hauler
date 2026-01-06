<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$privacyPath = __DIR__ . '/../../docs/PRIVACY.md';
$privacyMarkdown = is_file($privacyPath) ? (string)file_get_contents($privacyPath) : '';

$renderInline = static function (string $text): string {
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
    $escaped = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
    if ($inCode) {
      $output .= '<code>' . $escaped . '</code>';
    } else {
      $output .= $escaped;
    }
  }
  return $output;
};

$renderMarkdown = static function (string $markdown) use ($renderInline): string {
  $lines = preg_split("/\r\n|\r|\n/", $markdown);
  if ($lines === false) {
    return '';
  }

  $html = '';
  $paragraph = '';
  $listType = null;
  $inCode = false;
  $codeBuffer = [];

  $flushParagraph = static function () use (&$paragraph, &$html, $renderInline): void {
    if (trim($paragraph) !== '') {
      $html .= '<p>' . $renderInline(trim($paragraph)) . '</p>';
      $paragraph = '';
    }
  };

  $closeList = static function () use (&$listType, &$html): void {
    if ($listType !== null) {
      $html .= '</' . $listType . '>';
      $listType = null;
    }
  };

  foreach ($lines as $line) {
    if (str_starts_with($line, '```')) {
      if ($inCode) {
        $escapedCode = htmlspecialchars(implode("\n", $codeBuffer), ENT_QUOTES, 'UTF-8');
        $html .= '<pre><code>' . $escapedCode . '</code></pre>';
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
      $codeBuffer[] = $line;
      continue;
    }

    if (trim($line) === '') {
      $flushParagraph();
      $closeList();
      continue;
    }

    if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $matches)) {
      $flushParagraph();
      $closeList();
      $level = strlen($matches[1]);
      $html .= '<h' . $level . '>' . $renderInline(trim($matches[2])) . '</h' . $level . '>';
      continue;
    }

    if (preg_match('/^\s*(?:[-*]|\d+\.)\s+(.*)$/', $line, $matches)) {
      $flushParagraph();
      $itemContent = $renderInline(trim($matches[1]));
      $newListType = preg_match('/^\s*\d+\./', $line) ? 'ol' : 'ul';
      if ($listType !== $newListType) {
        $closeList();
        $listType = $newListType;
        $html .= '<' . $listType . '>';
      }
      $html .= '<li>' . $itemContent . '</li>';
      continue;
    }

    if ($paragraph !== '') {
      $paragraph .= ' ';
    }
    $paragraph .= $line;
  }

  if ($inCode) {
    $escapedCode = htmlspecialchars(implode("\n", $codeBuffer), ENT_QUOTES, 'UTF-8');
    $html .= '<pre><code>' . $escapedCode . '</code></pre>';
  }

  $flushParagraph();
  $closeList();

  return $html;
};

$privacyHtml = $renderMarkdown($privacyMarkdown);

ob_start();
?>
<section class="card">
  <div class="card-header">
    <h2>Privacy Policy</h2>
    <p class="muted">Public disclosure for Discord compliance.</p>
  </div>

  <div class="content">
    <?= $privacyHtml ?>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
