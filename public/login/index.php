<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use App\Auth\Auth;

/**
 * Login starts EVE SSO authorization flow.
 * First user to authenticate becomes admin (platform bootstrap).
 */

if ($db === null || !isset($services['sso_login'])) {
  http_response_code(503);
  echo "SSO unavailable (database offline).";
  exit;
}

// Scopes needed for corp contracts (centralized in config).
$scopes = $config['sso']['scopes'] ?? ['esi-contracts.read_corporation_contracts.v1'];

$state = bin2hex(random_bytes(16));
$_SESSION['sso_state'] = $state;

$authorizeUrl = $services['sso_login']->authorizeUrl($state, $scopes);

http_response_code(302);
header('Location: ' . $authorizeUrl);
exit;
