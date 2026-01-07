<?php
declare(strict_types=1);

use App\Auth\Auth;

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$canRights = !empty($authCtx['user_id']) && Auth::can($authCtx, 'user.manage');
$isDispatcher = !empty($authCtx['user_id']) && Auth::hasRole($authCtx, 'dispatcher');
$isAdmin = !empty($authCtx['user_id']) && Auth::hasRole($authCtx, 'admin');
$dispatcherLimited = $isDispatcher && !$isAdmin;
$adminPerms = [
  'corp.manage',
  'esi.manage',
  'webhook.manage',
  'pricing.manage',
  'user.manage',
  'haul.request.manage',
  'haul.assign',
];
$hasAnyAdmin = false;
if (!empty($authCtx['user_id'])) {
  foreach ($adminPerms as $permKey) {
    if (Auth::can($authCtx, $permKey)) {
      $hasAnyAdmin = true;
      break;
    }
  }
}
$requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$requestPath = (string)(parse_url($requestUri, PHP_URL_PATH) ?? '/');
if ($basePath !== '' && strpos($requestPath, $basePath) === 0) {
  $requestPath = substr($requestPath, strlen($basePath));
  if ($requestPath === '') {
    $requestPath = '/';
  }
}
$isActivePath = static function (string $currentPath, string $targetPath): bool {
  $baseTarget = rtrim($targetPath, '/');
  if ($baseTarget === '') {
    $baseTarget = '/';
  }

  return $currentPath === $targetPath
    || $currentPath === $baseTarget
    || $currentPath === $baseTarget . '/'
    || $currentPath === $baseTarget . '.php'
    || strpos($currentPath, $baseTarget . '/') === 0;
};
$isRightsPath = static function (string $currentPath): bool {
  return strpos($currentPath, '/rights') === 0
    || strpos($currentPath, '/admin/rights') === 0;
};
$isItemActive = static function (array $item, string $currentPath) use ($isActivePath, $isRightsPath): bool {
  if (($item['match'] ?? '') === 'rights') {
    return $isRightsPath($currentPath);
  }

  return $isActivePath($currentPath, $item['path']);
};
$isAdminArea = strpos($requestPath, '/admin') === 0
  || $isRightsPath($requestPath);
$subNavGroups = $dispatcherLimited
  ? [
    [
      'label' => 'Admin',
      'items' => [
        ['label' => 'Users', 'path' => '/admin/users/', 'perm' => null],
      ],
    ],
  ]
  : [
    [
      'label' => 'Platform',
      'items' => [
        ['label' => 'Admin', 'path' => '/admin/', 'perm' => null],
        ['label' => 'Users', 'path' => '/admin/users/', 'perm' => 'user.manage'],
        ['label' => 'Rights', 'path' => '/rights/', 'perm' => 'user.manage', 'requires_rights' => true, 'match' => 'rights'],
      ],
    ],
    [
      'label' => 'Operations',
      'items' => [
        ['label' => 'Hauling', 'path' => '/admin/hauling/', 'perm' => 'haul.request.manage'],
        ['label' => 'Pricing', 'path' => '/admin/pricing/', 'perm' => 'pricing.manage'],
        ['label' => 'Defaults', 'path' => '/admin/defaults/', 'perm' => 'pricing.manage'],
        ['label' => 'Access', 'path' => '/admin/access/', 'perm' => 'corp.manage'],
      ],
    ],
    [
      'label' => 'Integrations',
      'items' => [
        ['label' => 'Discord', 'path' => '/admin/discord/', 'perm' => 'webhook.manage'],
        ['label' => 'Discord Links', 'path' => '/admin/discord-links/', 'perm' => 'webhook.manage'],
        ['label' => 'Webhooks', 'path' => '/admin/webhooks/', 'perm' => 'webhook.manage'],
        ['label' => 'ESI', 'path' => '/admin/esi/', 'perm' => 'esi.manage'],
      ],
    ],
    [
      'label' => 'Maintenance',
      'items' => [
        ['label' => 'Cache', 'path' => '/admin/cache/', 'perm' => 'esi.manage'],
        ['label' => 'Cron', 'path' => '/admin/cron/', 'perm' => 'esi.manage'],
      ],
    ],
  ];
?>
<?php if ($isAdminArea): ?>
  <nav class="admin-subnav" aria-label="Admin sections">
    <?php foreach ($subNavGroups as $group): ?>
      <?php
        $groupItems = [];
        foreach ($group['items'] as $item) {
          $requiresRights = !empty($item['requires_rights']);
          $requiredRoles = $item['roles'] ?? [];
          $isAllowed = false;
          if ($requiredRoles !== []) {
            $isAllowed = array_intersect($requiredRoles, $authCtx['roles'] ?? []) !== [];
          } elseif ($dispatcherLimited) {
            $isAllowed = true;
          } elseif ($item['perm'] === null) {
            $isAllowed = $hasAnyAdmin;
          } elseif ($requiresRights) {
            $isAllowed = $canRights && Auth::can($authCtx, $item['perm']);
          } else {
            $isAllowed = Auth::can($authCtx, $item['perm']);
          }

          if ($isAllowed) {
            $groupItems[] = $item;
          }
        }
        if ($groupItems === []) {
          continue;
        }
        $groupActive = false;
        foreach ($groupItems as $item) {
          if ($isItemActive($item, $requestPath)) {
            $groupActive = true;
            break;
          }
        }
      ?>
      <div class="admin-subnav-group<?= $groupActive ? ' is-active' : '' ?>">
        <div class="admin-subnav-label"><?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="admin-subnav-links">
          <?php foreach ($groupItems as $item): ?>
            <?php $isActive = $isItemActive($item, $requestPath); ?>
            <a class="nav-link<?= $isActive ? ' is-active' : '' ?>" href="<?= ($basePath ?: '') . $item['path'] ?>">
              <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </nav>
<?php endif; ?>
