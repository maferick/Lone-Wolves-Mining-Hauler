<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use App\Auth\Auth;

/**
 * Login shows the EVE SSO entry point or starts the SSO authorization flow.
 * - member mode: basic profile only (no extra ESI scopes)
 * - esi mode: full scopes for admin ESI token management
 */

if ($db === null || !isset($services['sso_login'])) {
  http_response_code(503);
  echo "SSO unavailable (database offline).";
  exit;
}

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Login';
$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);

$mode = strtolower((string)($_GET['mode'] ?? 'member'));
if (!in_array($mode, ['member', 'esi'], true)) {
  $mode = 'member';
}

$start = isset($_GET['start']);
if ($start) {
  if ($mode === 'esi') {
    Auth::requireLogin($authCtx);
    Auth::requireAdmin($authCtx);
  }

  $scopes = $mode === 'esi' ? ($config['sso']['scopes'] ?? ['esi-contracts.read_corporation_contracts.v1']) : [];
  $state = bin2hex(random_bytes(16));
  $_SESSION['sso_state'] = $state;
  $_SESSION['sso_mode'] = $mode;

  $returnPath = (string)($_GET['return'] ?? '');
  if ($returnPath !== '' && str_starts_with($returnPath, '/') && !str_starts_with($returnPath, '//')) {
    $_SESSION['sso_return_to'] = $returnPath;
  }

  $authorizeUrl = $services['sso_login']->authorizeUrl($state, $scopes);

  http_response_code(302);
  header('Location: ' . $authorizeUrl);
  exit;
}

$loginStartUrl = ($basePath ?: '') . '/login/?start=1';

ob_start();
?>
<section class="card">
  <div class="card-header">
    <h2>Log in with EVE Online</h2>
    <p class="muted">Members must authenticate before accessing quotes and internal tools.</p>
  </div>
  <div class="content" style="display:flex; flex-direction:column; gap:12px;">
    <?php if (!empty($authCtx['user_id'])): ?>
      <div class="pill subtle">Signed in as <?= htmlspecialchars((string)($authCtx['display_name'] ?? 'Member'), ENT_QUOTES, 'UTF-8') ?></div>
      <a class="btn ghost" href="<?= ($basePath ?: '') ?>/">Return to dashboard</a>
    <?php endif; ?>
    <a class="sso-button" href="<?= htmlspecialchars($loginStartUrl, ENT_QUOTES, 'UTF-8') ?>">
      <img src="https://web.ccpgamescdn.com/eveonlineassets/developers/eve-sso-login-black-small.png" alt="Log in with EVE Online" />
    </a>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../src/Views/layout.php';
exit;
