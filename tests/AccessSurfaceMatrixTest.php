<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Auth/Auth.php';

use App\Auth\Auth;

$assertAccess = static function (array $ctx, string $surface, bool $expected): void {
  $actual = Auth::canAccess($ctx, $surface);
  if ($actual !== $expected) {
    throw new RuntimeException(sprintf(
      'Surface %s expected %s but got %s.',
      $surface,
      $expected ? 'allowed' : 'denied',
      $actual ? 'allowed' : 'denied'
    ));
  }
};

$adminCtx = [
  'user_id' => 1,
  'roles' => ['admin'],
  'perms' => [],
  'is_admin' => true,
  'access_granted' => false,
];

foreach (['home', 'operations', 'profile', 'wiki', 'hall_of_fame', 'my_contracts'] as $surface) {
  $assertAccess($adminCtx, $surface, true);
}

$subadminCtxEntitled = [
  'user_id' => 2,
  'roles' => ['subadmin'],
  'perms' => [
    'haul.request.read',
    'haul.request.manage',
    'haul.assign',
    'haul.execute',
    'pricing.manage',
    'webhook.manage',
    'esi.manage',
    'user.manage',
    'corp.manage',
  ],
  'is_admin' => false,
  'access_granted' => true,
];

$subadminExpected = [
  'home' => true,
  'operations' => true,
  'profile' => true,
  'wiki' => true,
  'hall_of_fame' => true,
  'my_contracts' => true,
];
foreach ($subadminExpected as $surface => $expected) {
  $assertAccess($subadminCtxEntitled, $surface, $expected);
}

$subadminCtxNoEntitlement = $subadminCtxEntitled;
$subadminCtxNoEntitlement['access_granted'] = false;
foreach (array_keys($subadminExpected) as $surface) {
  $assertAccess($subadminCtxNoEntitlement, $surface, false);
}

$requesterCtx = [
  'user_id' => 3,
  'roles' => ['requester'],
  'perms' => ['haul.request.create', 'haul.request.read'],
  'is_admin' => false,
  'access_granted' => true,
];

$assertAccess($requesterCtx, 'home', true);
$assertAccess($requesterCtx, 'operations', true);
$assertAccess($requesterCtx, 'my_contracts', true);
$assertAccess($requesterCtx, 'profile', true);
$assertAccess($requesterCtx, 'wiki', false);
$assertAccess($requesterCtx, 'hall_of_fame', true);
$assertAccess($requesterCtx, 'request', true);

$haulerCtx = [
  'user_id' => 4,
  'roles' => ['hauler'],
  'perms' => ['haul.request.read', 'haul.execute'],
  'is_admin' => false,
  'access_granted' => true,
];

$assertAccess($haulerCtx, 'operations', true);
$assertAccess($haulerCtx, 'wiki', true);
$assertAccess($haulerCtx, 'hall_of_fame', true);

$dispatcherCtx = [
  'user_id' => 5,
  'roles' => ['dispatcher'],
  'perms' => ['haul.request.read', 'haul.request.manage', 'haul.assign'],
  'is_admin' => false,
  'access_granted' => true,
];

$assertAccess($dispatcherCtx, 'operations', true);
$assertAccess($dispatcherCtx, 'wiki', true);
$assertAccess($dispatcherCtx, 'hall_of_fame', true);

$nonEntitledCtx = [
  'user_id' => 6,
  'roles' => ['requester'],
  'perms' => ['haul.request.read'],
  'is_admin' => false,
  'access_granted' => false,
];

$assertAccess($nonEntitledCtx, 'home', false);
$assertAccess($nonEntitledCtx, 'operations', false);

echo "Access surface matrix tests passed.\n";
