<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Services/AuthzService.php';

use App\Services\AuthzService;

$cases = [
  ['in_scope' => true, 'entitled' => true, 'expected' => 'compliant'],
  ['in_scope' => true, 'entitled' => false, 'expected' => 'de-entitled'],
  ['in_scope' => false, 'entitled' => true, 'expected' => 'drift'],
  ['in_scope' => false, 'entitled' => false, 'expected' => 'out-of-scope'],
];

foreach ($cases as $case) {
  $result = AuthzService::classifyCompliance($case['in_scope'], $case['entitled']);
  if (($result['key'] ?? '') !== $case['expected']) {
    throw new RuntimeException(
      'Expected ' . $case['expected'] . ' but got ' . ($result['key'] ?? 'null')
    );
  }
}

echo "AuthzService compliance tests passed.\n";
