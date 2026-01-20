# Identity Governance Policy (IGA / JML)

## Purpose
Define how identity lifecycle, eligibility, entitlements, and access are governed across the Portal and Discord integration, using IGA/JML terminology that supports multiple governance modes.

## Scope
Applies to all Portal users and linked Discord accounts that participate in hauling access and role-based permissions. The policy covers:
- Identity lifecycle and retention.
- Eligibility (in-scope) determination.
- Entitlement decisions for hauling access.
- Effective access and reconciliation between systems.
- Audit and monitoring expectations.

## Definitions
- **Identity status**: The Portal account lifecycle state (e.g., active, suspended, disabled).
- **Eligibility (in scope)**: Whether a user is in scope for access based on the configured access policy.
- **Entitlement**: The authoritative decision that a user should have hauling access.
- **Effective access**: Whether access is actually granted at runtime.
- **Policy outcome**: Compliance classification derived from eligibility and entitlement.
- **Drift**: A mismatch between authoritative entitlement and effective access (or observed access state).

## Joiner / Mover / Leaver (JML)
- **Joiner**: A newly linked identity that becomes eligible and is granted entitlement according to the active governance mode.
- **Mover**: An identity whose eligibility or entitlement changes (for example, scope changes or roles change).
- **Leaver**: An identity that is no longer eligible or is de-entitled and must have access removed.

## Core Policy (applies to all modes)
- **Identity retention**: Portal identities are retained even when access is revoked; lifecycle status and entitlement are managed independently.
- **Lifecycle vs. entitlement**: Eligibility (in scope) and entitlement are evaluated separately. Effective access requires both, unless privileged access exemptions apply.
- **Auditability**: Entitlement reconciliations and privileged access interventions are logged for review.

## Governance Modes
### Discord-leading mode (Discord authoritative)
- **Entitlement source**: Discord role membership is authoritative for hauling access. Entitlements are derived from the most recent Discord role snapshot.
- **Snapshot freshness**: If the snapshot is stale, entitlement fails closed (treated as not entitled) until a new snapshot is processed.
- **Reconciliation**: Reconciliation compares Portal eligibility with the Discord-derived entitlement and enforces lifecycle changes (e.g., suspension) when eligibility or entitlement does not align.
- **Revocation**: Users who are out of scope or not entitled are de-entitled in the Portal and have access removed on reconciliation.

### Portal-leading mode (Portal authoritative)
- **Entitlement source**: Portal role assignments are authoritative for hauling access.
- **Provisioning to Discord**: Portal-managed entitlements can be synced to Discord roles via role sync operations for linked users.
- **Reconciliation**: Reconciliation compares Portal eligibility with Portal-derived entitlements; Discord roles are expected to converge through sync operations.
- **Snapshot considerations**: Effective access still depends on the latest Discord snapshot used by the Portal. If the snapshot is stale, effective access fails closed until refreshed, which can surface as drift.

## Privileged access / break-glass
Privileged users (admin/subadmin/break-glass allowlist) may receive access exemptions to prevent lockout during reconciliation or operational recovery. These exemptions are tracked separately and should be reviewed regularly. Privileged access does not replace normal entitlement governance; it is a controlled exception path.

## Monitoring & audit
- **Status map**: The Admin → Discord → Portal ↔ Discord status map provides a consolidated view of identity status, eligibility, entitlement, effective access, and policy outcome for ongoing monitoring.
- **Audit logs**: Reconciliation and privileged access events are recorded for audit review, including decisions and timestamps.
