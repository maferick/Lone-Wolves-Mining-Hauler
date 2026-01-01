<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use App\Auth\Auth;

/**
 * Login starts EVE SSO authorization flow.
 * First user to authenticate becomes admin (platform bootstrap).
 */

// Scopes needed for corp contracts (can evolve). Keep in config later.
$scopes = [
  'esi-contracts.read_corporation_contracts.v1',
  'esi-contracts.read_character_contracts.v1',
  // optional for future expansions:
  'esi-characters.read_corporation_roles.v1',
];

$state = bin2hex(random_bytes(16));
$_SESSION['sso_state'] = $state;

$authorizeUrl = $services['sso_login']->authorizeUrl($state, $scopes);

http_response_code(302);
header('Location: ' . $authorizeUrl);
exit;
