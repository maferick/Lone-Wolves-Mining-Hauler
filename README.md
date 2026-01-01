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
