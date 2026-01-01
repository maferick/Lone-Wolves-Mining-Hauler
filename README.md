# Corp Hauling (In-house PushX-style)

Dark, modern 2026 UI skeleton + a strict include pattern:

**config → dbfunctions → auth → services → route handler**

## Run locally (quick)
- Point your web server document root to `public/`
- Copy `env.example` to `.env` and fill DB credentials
- Import DB schema (from your `/db` folder in the other bundle)

## Folder layout
- `public/` – front controller + static assets
- `src/bootstrap.php` – canonical include chain
- `src/Db/dbfunctions.php` – **single entrypoint** for DB access
- `src/Auth/auth.php` – auth placeholder (SSO/RBAC next)
- `src/Services/` – service layer (ESI, routing, pricing)
- `src/Views/` – minimal PHP views + layout

## Next build step
- Implement ESI client:
  - ETag + `esi_cache`
  - Token refresh with `sso_token`
  - Corp contracts pull → `esi_corp_contract` tables


## ESI contracts pull (CLI)
1) Ensure `.env` has:
- `EVE_CLIENT_ID`
- `EVE_CLIENT_SECRET`
- DB credentials
2) Insert an `sso_token` record for a director character (owner_type=character, owner_id=<char_id>).
3) Run:
```bash
php bin/pull_contracts.php <corp_id> <character_id>
```

The script will:
- refresh token if needed (`sso_token`)
- call ESI with ETag revalidation
- upsert into `esi_corp_contract` and `esi_corp_contract_item`


## Create database from .env
If you have server-level credentials available (dev/staging), you can bootstrap the DB automatically:

```bash
cp env.example .env
# edit .env (DB_* and optionally DB_ROOT_*)
php createdb.php --import=../db
```

- `--import` can point to a directory containing `*.sql` or a single `.sql` file.


## Subdirectory hosting (/hauling)
Because this site is hosted under a subdirectory, set:
- `APP_BASE_URL="http://killsineve.online/hauling"`
- `APP_BASE_PATH="/hauling"`

This ensures all links and assets resolve to `/hauling/*` (e.g., `/hauling/health`, `/hauling/docs`).


## API entrypoints (physical)
Automation endpoints live under `/public/api/` so they never depend on rewrite rules.

Available endpoints:
- `/hauling/api/health`
- `/hauling/api/contracts/sync?corp_id=XXX&character_id=YYY`

All future automation (Discord webhooks, cron jobs, ESI pulls) should be added here.


## Import order gotcha (drop scripts)
`db/900_drop_all.sql` is a utility script and **must not** be imported during normal setup.

The bootstrap now skips any `*drop*` SQL files by default.
If you explicitly want to run them (danger), pass:

```bash
php createdb.php --import=./db --include-drop=1
```
