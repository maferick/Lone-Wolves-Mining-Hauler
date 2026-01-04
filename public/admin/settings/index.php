<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'corp.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);
if ($corpId <= 0) { http_response_code(400); echo "No corp context"; exit; }

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Corp Settings';

$msg = null;
$alertType = 'pill';
$uploadMsg = null;
$resetMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $corpName = trim((string)($_POST['corp_name'] ?? ''));
  $ticker = trim((string)($_POST['ticker'] ?? ''));
  $allianceId = trim((string)($_POST['alliance_id'] ?? ''));
  $allianceName = trim((string)($_POST['alliance_name'] ?? ''));

  $allianceIdVal = ($allianceId === '') ? null : (int)$allianceId;
  if ($corpName === '') $corpName = 'Corporation';

  $db->tx(function(Db $db) use ($corpId, $corpName, $ticker, $allianceIdVal, $allianceName, $authCtx) {
    $db->execute(
      "UPDATE corp
          SET corp_name=:cn, ticker=:t, alliance_id=:aid, alliance_name=:an, updated_at=CURRENT_TIMESTAMP
        WHERE corp_id=:cid",
      ['cn'=>$corpName,'t'=>$ticker ?: null,'aid'=>$allianceIdVal,'an'=>$allianceName ?: null,'cid'=>$corpId]
    );

    $db->audit($corpId, $authCtx['user_id'], $authCtx['character_id'], 'corp.update', 'corp', (string)$corpId, null, [
      'corp_name'=>$corpName,'ticker'=>$ticker,'alliance_id'=>$allianceIdVal,'alliance_name'=>$allianceName
    ], $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);

    $db->execute(
      "INSERT INTO app_setting (corp_id, setting_key, setting_json, updated_by_user_id)
       VALUES (:cid, 'corp.profile', JSON_OBJECT('corp_id', :json_cid, 'corp_name', :cn, 'ticker', :t, 'alliance_id', :aid, 'alliance_name', :an), :uid)
       ON DUPLICATE KEY UPDATE setting_json=VALUES(setting_json), updated_by_user_id=VALUES(updated_by_user_id)",
      [
        'cid'=>$corpId,
        'json_cid'=>$corpId,
        'cn'=>$corpName,
        't'=>$ticker ?: null,
        'aid'=>$allianceIdVal,
        'an'=>$allianceName ?: null,
        'uid'=>$authCtx['user_id']
      ]
    );
  });

  $msg = "Saved.";
}

$brandDir = __DIR__ . '/../../assets';
$brandBaseUrl = ($basePath ?: '') . '/assets';
$brandDefaultsDir = $brandDir . '/defaults';
$logoPngPath = $brandDir . '/logo.png';
$logoJpgPath = $brandDir . '/logo.jpg';
$isResetRequest = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_branding']);
$brandingDefaults = [
  'panel_intensity' => 60,
  'background_all_pages' => false,
];
$brandingUi = $brandingDefaults;

$brandingRow = $db->one(
  "SELECT setting_json
     FROM app_setting
    WHERE corp_id = :cid AND setting_key = 'branding.ui' LIMIT 1",
  ['cid' => $corpId]
);
if ($brandingRow && !empty($brandingRow['setting_json'])) {
  $decoded = json_decode((string)$brandingRow['setting_json'], true);
  if (is_array($decoded)) {
    $brandingUi = array_merge($brandingUi, $decoded);
  }
}

function resizeAndSaveImage(
  array $file,
  string $destPath,
  int $maxWidth,
  int $maxHeight,
  array &$errors,
  string $outputFormat = 'jpg'
): bool
{
  if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
    return false;
  }

  if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > 8 * 1024 * 1024) {
    $errors[] = 'Image upload must be less than 8MB.';
    return false;
  }

  $imageInfo = getimagesize($file['tmp_name']);
  if ($imageInfo === false) {
    $errors[] = 'Uploaded file is not a valid image.';
    return false;
  }

  $mimeType = $imageInfo['mime'] ?? '';
  $srcImage = null;
  switch ($mimeType) {
    case 'image/jpeg':
      $srcImage = imagecreatefromjpeg($file['tmp_name']);
      break;
    case 'image/png':
      $srcImage = imagecreatefrompng($file['tmp_name']);
      break;
    case 'image/webp':
      $srcImage = imagecreatefromwebp($file['tmp_name']);
      break;
    default:
      $errors[] = 'Only JPG, PNG, or WebP images are supported.';
      return false;
  }

  if (!$srcImage) {
    $errors[] = 'Failed to read the uploaded image.';
    return false;
  }

  [$width, $height] = $imageInfo;
  $scale = min($maxWidth / $width, $maxHeight / $height, 1);
  $targetWidth = (int)round($width * $scale);
  $targetHeight = (int)round($height * $scale);

  if ($targetWidth <= 0 || $targetHeight <= 0) {
    $errors[] = 'Invalid image dimensions.';
    imagedestroy($srcImage);
    return false;
  }

  $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
  imagealphablending($targetImage, false);
  imagesavealpha($targetImage, true);
  imagecopyresampled($targetImage, $srcImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

  if (!is_dir(dirname($destPath))) {
    mkdir(dirname($destPath), 0775, true);
  }

  $saved = false;
  switch ($outputFormat) {
    case 'png':
      $saved = imagepng($targetImage, $destPath, 6);
      break;
    case 'webp':
      $saved = imagewebp($targetImage, $destPath, 86);
      break;
    case 'jpg':
    case 'jpeg':
    default:
      $saved = imagejpeg($targetImage, $destPath, 86);
      break;
  }
  imagedestroy($srcImage);
  imagedestroy($targetImage);

  if (!$saved) {
    $errors[] = 'Unable to save the resized image.';
    return false;
  }

  return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $errors = [];
  $panelIntensity = (int)($_POST['panel_intensity'] ?? $brandingUi['panel_intensity']);
  $panelIntensity = max(0, min(100, $panelIntensity));
  $backgroundAllPages = !empty($_POST['background_all_pages']);
  $uploads = [
    'background_image' => [
      'path' => $brandDir . '/background.jpg',
      'label' => 'Background',
      'maxWidth' => 2400,
      'maxHeight' => 1400,
      'format' => 'jpg',
    ],
    'logo_image' => [
      'path' => $brandDir . '/logo.png',
      'label' => 'Logo',
      'maxWidth' => 1400,
      'maxHeight' => 900,
      'format' => 'png',
    ],
  ];

  foreach ($uploads as $field => $meta) {
    if (!empty($_FILES[$field]['tmp_name'])) {
      $defaultPath = $brandDefaultsDir . '/' . basename($meta['path']);
      if (!is_dir($brandDefaultsDir)) {
        mkdir($brandDefaultsDir, 0775, true);
      }
      if ($field === 'logo_image' && !file_exists($defaultPath) && file_exists($logoJpgPath)) {
        $legacyDefault = $brandDefaultsDir . '/logo.jpg';
        if (!file_exists($legacyDefault)) {
          copy($logoJpgPath, $legacyDefault);
        }
      }
      if (!file_exists($defaultPath) && file_exists($meta['path'])) {
        copy($meta['path'], $defaultPath);
      }

      if (!resizeAndSaveImage(
        $_FILES[$field],
        $meta['path'],
        $meta['maxWidth'],
        $meta['maxHeight'],
        $errors,
        $meta['format']
      )) {
        $errors[] = $meta['label'] . ' image upload failed.';
      }
    }
  }

  $db->execute(
    "INSERT INTO app_setting (corp_id, setting_key, setting_json, updated_by_user_id)
     VALUES (:cid, 'branding.ui', JSON_OBJECT('panel_intensity', :panel, 'background_all_pages', :background_all), :uid)
     ON DUPLICATE KEY UPDATE setting_json=VALUES(setting_json), updated_by_user_id=VALUES(updated_by_user_id)",
    [
      'cid' => $corpId,
      'panel' => $panelIntensity,
      'background_all' => $backgroundAllPages ? 1 : 0,
      'uid' => $authCtx['user_id'],
    ]
  );
  $brandingUi = [
    'panel_intensity' => $panelIntensity,
    'background_all_pages' => $backgroundAllPages,
  ];

  if (!empty($errors)) {
    $uploadMsg = implode(' ', array_unique($errors));
    $alertType = 'pill pill-danger';
  } elseif (!empty($_FILES['background_image']['tmp_name']) || !empty($_FILES['logo_image']['tmp_name'])) {
    $uploadMsg = 'Branding images updated.';
  }
}

if ($isResetRequest) {
  $resetErrors = [];
  $defaults = [
    $brandDefaultsDir . '/background.jpg' => $brandDir . '/background.jpg',
    $brandDefaultsDir . '/logo.png' => $brandDir . '/logo.png',
    $brandDefaultsDir . '/logo.jpg' => $brandDir . '/logo.jpg',
  ];

  foreach ($defaults as $defaultPath => $targetPath) {
    if (file_exists($defaultPath)) {
      if (!copy($defaultPath, $targetPath)) {
        $resetErrors[] = 'Failed to restore ' . basename($targetPath) . '.';
      }
    } else {
      $resetErrors[] = 'Default ' . basename($targetPath) . ' not found.';
    }
  }

  if (!empty($resetErrors)) {
    $uploadMsg = implode(' ', array_unique($resetErrors));
    $alertType = 'pill pill-danger';
  } else {
    $resetMsg = 'Branding images reset to default.';
  }
}

$logoFilename = file_exists($logoPngPath) ? 'logo.png' : 'logo.jpg';
$logoUrl = $brandBaseUrl . '/' . $logoFilename;

$corp = $db->one("SELECT corp_id, corp_name, ticker, alliance_id, alliance_name FROM corp WHERE corp_id=:cid", ['cid'=>$corpId]);

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="card">
  <div class="card-header">
    <h2>Corporation Profile</h2>
    <p class="muted">These values drive branding, access control, and ESI context.</p>
  </div>

  <div class="content">
    <?php if ($msg): ?>
      <div class="pill"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($uploadMsg): ?>
      <div class="<?= htmlspecialchars($alertType, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($uploadMsg, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($resetMsg): ?>
      <div class="pill"><?= htmlspecialchars($resetMsg, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
      <div class="row">
        <div>
          <div class="label">Corp Name</div>
          <input class="input" name="corp_name" value="<?= htmlspecialchars((string)($corp['corp_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
        </div>
        <div>
          <div class="label">Ticker</div>
          <input class="input" name="ticker" value="<?= htmlspecialchars((string)($corp['ticker'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
        </div>
      </div>

      <div class="row">
        <div>
          <div class="label">Alliance ID (optional)</div>
          <input class="input" name="alliance_id" value="<?= htmlspecialchars((string)($corp['alliance_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
        </div>
        <div>
          <div class="label">Alliance Name (optional)</div>
          <input class="input" name="alliance_name" value="<?= htmlspecialchars((string)($corp['alliance_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
        </div>
      </div>

      <div style="margin-top:16px;">
        <div class="label">Front Page Branding</div>
        <p class="muted" style="margin-top:6px;">Upload JPG/PNG/WebP. Images are auto-resized for best fit.</p>
        <p class="muted" style="margin-top:6px;">Reset restores the original images saved before the first upload.</p>
        <div class="row" style="margin-top:12px;">
          <div>
            <label class="label" for="panel_intensity">Card &amp; banner transparency</label>
            <input
              class="input"
              type="range"
              id="panel_intensity"
              name="panel_intensity"
              min="0"
              max="100"
              value="<?= htmlspecialchars((string)$brandingUi['panel_intensity'], ENT_QUOTES, 'UTF-8') ?>"
              style="padding:6px 0;"
            />
            <div class="muted" style="margin-top:6px;">
              Intensity: <span id="panel_intensity_value"><?= htmlspecialchars((string)$brandingUi['panel_intensity'], ENT_QUOTES, 'UTF-8') ?></span>%
            </div>
          </div>
          <div>
            <label class="label" for="background_all_pages">Background image visibility</label>
            <label class="pill" style="display:flex; align-items:center; gap:8px; margin-top:6px;">
              <input type="checkbox" id="background_all_pages" name="background_all_pages" value="1"<?= !empty($brandingUi['background_all_pages']) ? ' checked' : '' ?> />
              Show background on all pages
            </label>
          </div>
        </div>
        <div class="row" style="margin-top:12px;">
          <div>
            <label class="label" for="background_image">Background image</label>
            <input class="input" type="file" id="background_image" name="background_image" accept="image/jpeg,image/png,image/webp" />
            <?php if (file_exists($brandDir . '/background.jpg')): ?>
              <div class="muted" style="margin-top:6px;">Current: <a href="<?= htmlspecialchars($brandBaseUrl . '/background.jpg', ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View background</a></div>
            <?php endif; ?>
          </div>
          <div>
            <label class="label" for="logo_image">Logo image</label>
            <input class="input" type="file" id="logo_image" name="logo_image" accept="image/jpeg,image/png,image/webp" />
            <?php if (file_exists($logoPngPath) || file_exists($logoJpgPath)): ?>
              <div class="muted" style="margin-top:6px;">Current: <a href="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View logo</a></div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div style="margin-top:14px; display:flex; gap:10px;">
        <button class="btn" type="submit">Save</button>
        <button class="btn ghost" type="submit" name="reset_branding" value="1">Reset branding</button>
        <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/">Back</a>
      </div>
    </form>
  </div>
</section>
<script>
  const panelSlider = document.getElementById('panel_intensity');
  const panelValue = document.getElementById('panel_intensity_value');
  if (panelSlider && panelValue) {
    const updateValue = () => {
      panelValue.textContent = panelSlider.value;
    };
    panelSlider.addEventListener('input', updateValue);
    updateValue();
  }
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
