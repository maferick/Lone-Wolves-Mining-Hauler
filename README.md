# Corp Hauling

![EVE Online](https://img.shields.io/badge/EVE-Online-0A1F44?style=for-the-badge&logo=steam&logoColor=white)
![Status](https://img.shields.io/badge/Status-Active-2E7D32?style=for-the-badge)
![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?style=for-the-badge&logo=php&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-1E88E5?style=for-the-badge)

Dark, modern UI skeleton (2026) with a strict include chain and automation-first architecture:

**config → dbfunctions → auth → services → route handler**

## About
Built for corporation hauling operations with a clear separation of concerns, ESI-native services, and cron-driven automation.

Designed for single-corp ownership, predictable workflows, and minimal operational ambiguity.

**In-game contact:** `lellebel`

If you deploy this in New Eden, a short ping in-game is appreciated.  
Voluntary donations in ISK or other morale-boosting commodities are always welcome.

Setup assistance, custom extensions, or operational tuning are available by arrangement.

## What this is
- Corporation-internal hauling tooling
- ESI-driven contract ingestion and validation
- Automation endpoints with no rewrite dependencies
- Opinionated structure over flexibility

## What this is not
- A public hauling marketplace
- A multi-corp SaaS platform
- A UI builder or theme system
- A general ESI playground

## Project status
Active development.  
Core schema and service boundaries are stable; UI and automation evolve continuously.

Breaking changes may occur on `main` until a v1.0 release is tagged.

## License & responsibility
Provided as-is.  
Losses, explosions, or logistical mishaps remain the responsibility of the operator.

Read the code. Undock prepared.

## Run locally (quick)
- Point your web server document root to `public/`
- Copy `env.example` to `.env` and fill DB credentials
- Import the DB schema from `./db`

## Folder layout
- `public/` – front controller + static assets
- `src/bootstrap.php` – canonical include chain
- `src/Db/dbfunctions.php` – **single entrypoint** for DB access
- `src/Auth/auth.php` – auth placeholder (SSO/RBAC next)
- `src/Services/` – service layer (ESI, routing, pricing)
- `src/Views/` – minimal PHP views + layout

## ESI support
- ETag revalidation via `esi_cache`
- Token refresh via `sso_token`
- Corp contracts sync to `esi_corp_contract` tables

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

## Cron scheduler
Run the unified scheduler every minute. It triggers:
- Discord webhook delivery
- Discord outbox delivery
- ESI cron sync (tokens, structures, contracts, universe)
- Contract matching
- Async cron job processing

Recommended cron (every minute):
```
* * * * * php /path/to/hauling/bin/cron.php
```

## Create database from .env
If you have server-level credentials available (dev/staging), you can bootstrap the DB automatically:

```bash
cp env.example .env
# edit .env (DB_* and optionally DB_ROOT_*)
php createdb.php --import=./db
```

- `--import` can point to a directory containing `*.sql` or a single `.sql` file.
  In this repo, `./db` is the default schema directory.

## Subdirectory hosting (/hauling)
Because this site is hosted under a subdirectory, set:
- `APP_BASE_URL="http://killsineve.online/hauling"`
- `APP_BASE_PATH="/hauling"`

This ensures all links and assets resolve to `/hauling/*` (e.g., `/hauling/health`, `/hauling/docs`).

## API entrypoints (physical)
Automation endpoints live under `/public/api/` so they never depend on rewrite rules.

API endpoints require an `API_KEY` (sent as `X-Api-Key` header or `?api_key=`).

Example endpoints:
- `/hauling/api/health`
- `/hauling/api/contracts/sync?corp_id=XXX&character_id=YYY`
- `/hauling/api/webhooks/discord/test?webhook_id=123`
- `/hauling/api/jobs/webhooks?limit=25`

All future automation (Discord webhooks, cron jobs, ESI pulls) should be added here.

## Discord Integration
The hauling app supports Discord webhooks for ops notifications and a Discord bot for slash commands.

### Setup (Webhooks)
1) Create a webhook in your Discord channel:
   - Channel → Edit Channel → Integrations → Webhooks → New Webhook
2) Copy the webhook URL.
3) In **Admin → Discord → Channel Routing**, add a mapping for the event and paste the webhook URL.
4) Click **Send Test Message** to queue a test notification.

### Setup (Bot)
1) Create a Discord application and bot at https://discord.com/developers/applications
2) Copy the bot token and configure the environment variables:
```
DISCORD_BOT_TOKEN=...
DISCORD_APPLICATION_ID=...
DISCORD_PUBLIC_KEY=...
DISCORD_GUILD_ID=...   # optional for guild-scoped command registration
```
3) OAuth2 scopes: `bot` + `applications.commands`
4) Bot permissions:
   - Send Messages
   - Embed Links
   - Read Message History
5) In **Admin → Discord**, click **Register/Refresh Slash Commands**.

### Slash Commands
Available commands:
- `/quote` — Quote a haul using local routing data.
- `/request` — Lookup request status by ID or code.
- `/myrequests` — Show your last 5 requests (requires Discord ↔ account link).
- `/rates` — Snapshot of current rate plans.
- `/help` — Quick usage hints.
- `/ping` — Bot health check.

### Cron job
Ensure the delivery worker is enabled:
```
php bin/cron.php
```

### Security notes
- Bot tokens are secrets and must never be stored in the database.
- Interaction endpoint signatures are verified against `DISCORD_PUBLIC_KEY`.
- Discord delivery runs async via the outbox worker with rate limits and retries.

## Import order gotcha (drop scripts)
`db/900_drop_all.sql` is a utility script and **must not** be imported during normal setup.

The bootstrap now skips any `*drop*` SQL files by default.
If you explicitly want to run them (danger), pass:

```bash
php createdb.php --import=./db --include-drop=1
```
