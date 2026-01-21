# Rights surface mapping

This table summarizes which portal surfaces require entitlement (`hauling.member`) and which permissions unlock access. Admins bypass entitlement checks, but non-admin roles must satisfy both the entitlement requirement and the listed permissions (any-of). These mappings are enforced by `Auth::requireAccess()` and `Auth::requireAdmin()` to keep route checks consistent with the Rights matrix.

| Surface | Entitlement required | Permissions (any-of) |
| --- | --- | --- |
| Home (`/`) | Yes | — |
| Operations (`/operations`) | Yes | `haul.request.read`, `haul.request.manage`, `haul.assign`, `haul.execute`, `hauling.hauler` |
| My Contracts (`/my-contracts`) | Yes | `haul.request.read`, `haul.request.create` |
| Profile (`/profile`) | Yes | — |
| Wiki (`/wiki`) | Yes | `haul.request.manage`, `haul.execute` |
| Hall of Fame (`/hall-of-fame`) | Yes | `haul.request.read` |
| Request details (`/request`) | Yes | `haul.request.read`, `haul.request.create` |
| Admin dashboard (`/admin/`) | No | `corp.manage`, `esi.manage`, `webhook.manage`, `pricing.manage`, `user.manage`, `haul.request.manage`, `haul.assign` |
| Admin users (`/admin/users`) | No | `user.manage` |
| Admin rights (`/admin/rights`) | No | `user.manage` |
| Admin hauling (`/admin/hauling`) | No | `haul.request.manage` |
| Admin pricing (`/admin/pricing`) | No | `pricing.manage` |
| Admin defaults (`/admin/defaults`) | No | `pricing.manage` |
| Admin access (`/admin/access`) | No | `corp.manage` |
| Admin settings (`/admin/settings`) | No | `corp.manage` |
| Admin Discord (`/admin/discord`, `/admin/discord-links`, `/api/admin/discord/*`) | No | `webhook.manage` |
| Admin webhooks (`/admin/webhooks`) | No | `webhook.manage` |
| Admin ESI (`/admin/esi`) | No | `esi.manage` |
| Admin cache (`/admin/cache`) | No | `esi.manage` |
| Admin cron (`/admin/cron`) | No | `esi.manage` |
| Admin wiki check (`/admin/wiki-check`) | No | `user.manage` |
