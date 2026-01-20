<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Db/Db.php';
require_once __DIR__ . '/../src/Services/AuthzService.php';

use App\Db\Db;
use App\Services\AuthzService;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db = new Db($pdo);
$authz = new AuthzService($db);

$user = [
  'user_id' => 7,
  'status' => 'suspended',
  'session_revoked_at' => '',
];

$decision = $authz->computeReconcileDecision($user, true, false, false);

if (($decision['desired_status'] ?? '') !== 'active') {
  throw new RuntimeException('Expected admin to avoid suspension during reconcile.');
}
if (($decision['should_revoke_session'] ?? true) !== false) {
  throw new RuntimeException('Expected admin sessions to avoid revocation during reconcile.');
}

echo "Reconcile admin exemption test passed.\n";
