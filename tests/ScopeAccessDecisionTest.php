<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Services/AuthzService.php';

use App\Services\AuthzService;

$cases = [
  [
    'label' => 'Entitled but out of scope',
    'in_scope' => false,
    'entitled' => true,
    'is_admin' => false,
    'expected_access' => false,
    'expected_compliance' => 'drift',
  ],
  [
    'label' => 'Entitled in scope',
    'in_scope' => true,
    'entitled' => true,
    'is_admin' => false,
    'expected_access' => true,
    'expected_compliance' => 'compliant',
  ],
  [
    'label' => 'Admin bypass',
    'in_scope' => false,
    'entitled' => false,
    'is_admin' => true,
    'expected_access' => true,
    'expected_compliance' => 'admin-exempt',
  ],
];

foreach ($cases as $case) {
  $accessGranted = AuthzService::accessGranted(
    $case['in_scope'],
    $case['entitled'],
    $case['is_admin']
  );
  if ($accessGranted !== $case['expected_access']) {
    throw new RuntimeException(
      sprintf(
        '%s: expected access %s but got %s',
        $case['label'],
        $case['expected_access'] ? 'granted' : 'revoked',
        $accessGranted ? 'granted' : 'revoked'
      )
    );
  }

  $compliance = AuthzService::classifyComplianceForAdmin(
    $case['in_scope'],
    $case['entitled'],
    $case['is_admin']
  );
  if (($compliance['key'] ?? '') !== $case['expected_compliance']) {
    throw new RuntimeException(
      sprintf(
        '%s: expected compliance %s but got %s',
        $case['label'],
        $case['expected_compliance'],
        $compliance['key'] ?? 'null'
      )
    );
  }
}

echo "Scope access decision tests passed.\n";
