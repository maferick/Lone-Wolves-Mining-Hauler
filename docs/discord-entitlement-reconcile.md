# Discord Entitlement Reconcile Runbook

## Purpose
This job enforces the hybrid identity model:
- Portal users are retained.
- Entitlement source follows the governance mode (Discord-leading or Portal-leading).
- Out-of-scope or de-entitled users are suspended and stripped of portal roles.

The job is idempotent and logs changes to `audit_log` with the action `entitlement.reconcile`.

## Prerequisites
- A Discord role mapping for `hauling.member` in **Admin → Discord → Role Mapping** (required for Discord-leading mode).
- A recent Discord member snapshot (run **Discord onboarding scan**) when Discord-leading.
- Portal role assignments for `hauling.member` when Portal-leading.
- Database access.

## Run
```bash
php bin/reconcile_discord_entitlements.php
```

### Optional environment override
By default, snapshots older than 6 hours are treated as stale (entitlement fails closed).
Override with:
```bash
DISCORD_SNAPSHOT_MAX_AGE_SECONDS=21600 php bin/reconcile_discord_entitlements.php
```

## What the job does
For each portal user:
1. Determines portal **In Scope** status (based on access.login scope).
2. Determines **Entitled** status from the authoritative source:
   - Discord-leading: latest `hauling.member` snapshot (stale snapshots fail closed).
   - Portal-leading: portal rights (`hauling.member`) assignments.
3. Applies the decision matrix:
   - InScope + Entitled → `status=active`
   - InScope + NotEntitled → `status=suspended`, roles removed, sessions revoked
   - NotInScope + Entitled → `status=suspended`, roles removed, sessions revoked
   - NotInScope + NotEntitled → `status=suspended`, roles removed

Changes are logged to `audit_log` and active sessions are invalidated via `session_revoked_at`.

## Admin Self-Heal (scheduled cron)
To prevent admin lockouts caused by stale reconcile logic, the scheduled cron entry point (`bin/cron.php`) performs an idempotent self-heal step each time it runs. If an admin user is found in `status='suspended'`, the job restores:
- `app_user.status = 'active'`
- `app_user.session_revoked_at = NULL`

Only admins (including break-glass allowlisted admins) are remediated. Admin Self-Heal also applies to subadmins (admin-class). Admins in `status='disabled'` are **not** reactivated. Each remediation writes an `audit_log` entry with action `entitlement.admin_selfheal`, including the previous status, previous session revocation timestamp, the remediation timestamp, and `source='scheduled_cron'`.

### Monitoring
- SQL:
  ```sql
  SELECT action, entity_pk, after_json, created_at
    FROM audit_log
   WHERE action = 'entitlement.admin_selfheal'
   ORDER BY created_at DESC
   LIMIT 50;
  ```

## Verification
- Admin UI → Discord → **Portal → Discord status map**:
  - Confirm **Compliance** badges and **Access** states match expectations.
- SQL:
  ```sql
  SELECT action, entity_pk, after_json, created_at
    FROM audit_log
   WHERE action = 'entitlement.reconcile'
   ORDER BY created_at DESC
   LIMIT 50;
  ```

## Rollback / Recovery
1. Refresh the Discord snapshot (run onboarding scan).
2. Re-run the job to restore entitlements.
3. If a user must be restored immediately:
   - Set `app_user.status = 'active'`
   - Reassign portal roles
   - Ask the user to log in again (sessions were revoked)

## Break-glass admin access recovery
If admin access is lost due to role misconfiguration or entitlement drift, a temporary break-glass allowlist can restore admin access without deleting users.

1. Set **one** of the following environment variables (comma or whitespace separated):
   - `ADMIN_BREAKGLASS_USER_IDS` (portal `app_user.user_id` values)
   - `ADMIN_BREAKGLASS_EMAILS` (portal `app_user.email` values)
2. Restart the web process or reload env configuration.
3. Log in as the allowlisted user to regain admin access.

**Notes**
- Break-glass bypass is **off by default**.
- The allowlist only grants admin access; entitlement status is still evaluated for hauling/member routes.
- Remove the environment variable once access is recovered.

## Scheduling
Admin self-heal is enforced automatically by the regular cron execution of `bin/cron.php`; no additional cron configuration is required. The reconcile job itself is safe to run on a cron cadence (e.g., every 5–15 minutes) after the Discord scan schedule you prefer.
