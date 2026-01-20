# Discord Entitlement Reconcile Runbook

## Purpose
This job enforces the hybrid identity model:
- Portal users are retained.
- Discord role membership drives entitlement.
- Out-of-scope or de-entitled users are suspended and stripped of portal roles.

The job is idempotent and logs changes to `audit_log` with the action `entitlement.reconcile`.

## Prerequisites
- A Discord role mapping for `hauling.member` in **Admin → Discord → Role Mapping**.
- A recent Discord member snapshot (run **Discord onboarding scan**).
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
2. Determines Discord **Entitled** status from the latest `hauling.member` snapshot.
3. Applies the decision matrix:
   - InScope + Entitled → `status=active`
   - InScope + NotEntitled → `status=suspended`, roles removed, sessions revoked
   - NotInScope + Entitled → `status=suspended`, roles removed, sessions revoked
   - NotInScope + NotEntitled → `status=suspended`, roles removed

Changes are logged to `audit_log` and active sessions are invalidated via `session_revoked_at`.

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

## Scheduling
This job is safe to run on a cron cadence (e.g., every 5–15 minutes) after the Discord scan schedule you prefer.
