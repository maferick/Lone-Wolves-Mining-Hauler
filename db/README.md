# /db (MariaDB) – In-house PushX-style Hauling Platform

This folder is built to be **Codex-ready**: deterministic, versionable, and safe to run in CI.

## Files
- `001_schema.sql` – Core schema (RBAC, hauling workflow, ESI mirrors, cache, routing cache, webhooks, jobs, audit)
- `002_seed.sql` – Minimal seed (corp placeholder, roles/permissions, starter services & defaults)
- `003_views.sql` – Optional extra name-first views
- `900_drop_all.sql` – Nuclear option (drops everything). Use only in dev.

## Install (phpMyAdmin / MariaDB)
1. Create database + user (SQL tab in phpMyAdmin):
   - Use `create_db_and_user.sql` from the repo root, OR paste the snippet below.
2. Import in order:
   - `001_schema.sql`
   - `002_seed.sql`
   - `003_views.sql` (optional)

## Conventions (important for your “functionsdb” layer)
- UI never prints raw IDs by default:
  - Always join `eve_entity` (or use `v_haul_request_display` / `v_contract_display`) to show names.
- All ESI calls go through a cache strategy:
  - `esi_cache` is a generic store keyed by SHA-256 of request signature.
  - `eve_entity` is a persistent “name resolution cache” for IDs.

## Suggested next step
Create `/src/Db/functionsdb.php` with:
- A single PDO factory
- A `db_tx(callable $fn)` transaction wrapper
- Repository methods that always return *display-ready* objects (names resolved)
