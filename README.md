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

## Pricing discounts (Specials/Discounts)
- Rules live in `pricing_discount_rules` and are evaluated after the base price is calculated (after surcharges and security multipliers).
- By default, the engine applies the single best eligible discount. Stacking is only enabled when `pricing.discount_stacking` is enabled in `app_setting`, and only rules flagged `stackable` are applied in priority order.
- Floors are enforced twice: per rule (`min_final_price_isk`) and globally (ship-class minimum) to ensure deterministic, safe pricing.
- Every quote stores audit rows in `pricing_discount_audit` for eligibility reasons, applied rule, and discount amounts.

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

## Cache acceleration (Redis)
The cache table in MariaDB remains the system of record. Redis is optional and best-effort only.

**Config**
- `CACHE_DRIVER=db|write_through|tiered` (use `write_through`/`tiered` to enable Redis)
- `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASS`, `REDIS_DB`, `REDIS_TIMEOUT`, `REDIS_PREFIX`
- `METRICS_ENABLED=1` and `METRICS_FLUSH_INTERVAL_SECONDS=60` for cache metrics
- If `REDIS_*` is configured but `CACHE_DRIVER` is missing, startup logs emit:
  `Redis configured but CACHE_DRIVER not enabled; using DB-only.`

**Failure behavior**
- Writes always persist to MariaDB first (Redis failures are logged and ignored).
- Reads fall back to MariaDB on Redis miss or error.
- Redis TTLs align to the DB `expires_at` value, so expired entries are never served.

**Validating Redis hits**
- Use Admin → Cache → “Cache Performance” to check Redis/DB hit rates.
- Use Admin → Cache → “Redis Status” to verify driver, target, ping status, and last Redis error.
- For lower-level validation, inspect Redis keys (`esi_cache:<corp_id|shared>:<sha256>`).
- When Redis is enabled, you should see `PING` and a `SETEX` for `esi_cache:diag:ping` in `redis-cli MONITOR`.

**Metrics definitions**
- `cache_get_total`: total cache get attempts (Redis + DB).
- `cache_get_hit_redis`: cache hits served from Redis.
- `cache_get_hit_db`: cache hits served from MariaDB.
- `cache_set_total`: cache writes to MariaDB (write-through).
- `cache_set_redis_fail`: Redis write failures (best-effort).
- `avg DB cache-get time`: mean MariaDB lookup time for cache reads.

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
- Webhook delivery
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
- `APP_BASE_URL="https://example.com/hauling"`
- `APP_BASE_PATH="/hauling"`

This ensures all links and assets resolve to `/hauling/*` (e.g., `/hauling/health`, `/hauling/docs`).

## API entrypoints (physical)
Automation endpoints live under `/public/api/` so they never depend on rewrite rules.

API endpoints require an `API_KEY` (sent as `X-Api-Key` header or `?api_key=`).

Example endpoints:
- `/hauling/api/health`
- `/hauling/api/contracts/sync?corp_id=XXX&character_id=YYY`
- `/hauling/api/jobs/webhooks?limit=25`

All future automation (webhooks, cron jobs, ESI pulls) should be added here.

## Webhooks
The hauling app supports Discord and Slack webhooks for ops notifications.

### Setup (Discord Incoming Webhooks)
1) Create a webhook in your Discord channel:
   - Channel → Edit Channel → Integrations → Webhooks → New Webhook
2) Copy the webhook URL.
3) In **Admin → Webhooks**, add a new endpoint (Provider: Discord) and paste the webhook URL.
4) In **Admin → Webhooks → Event Routing**, select which events should deliver to the endpoint.

### Setup (Slack Incoming Webhooks)
1) In Slack, create an Incoming Webhook:
   - Workspace settings → Manage apps → Search for **Incoming Webhooks** → Add to Slack.
2) Choose a channel and copy the webhook URL.
3) In **Admin → Webhooks**, add a new endpoint (Provider: Slack) and paste the webhook URL.
4) In **Admin → Webhooks → Event Routing**, select which events should deliver to the endpoint.

## Discord Integration
The hauling app supports a Discord bot for slash commands, role sync, and hauling ops threads.

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
5) In **Admin → Discord**, configure Bot/OAuth settings, then click **Register/Refresh Slash Commands**.
6) In the Discord Developer Portal, set the **Interactions Endpoint URL** to:
```
https://example.com/api/discord/interactions/
```
7) Set the **Privacy Policy URL** to:
```
https://example.com/privacy
```

If hosting under a subdirectory, use `https://example.com/hauling/privacy`.
8) In the Discord Developer Portal, set the **Terms of Service URL** to:
```
https://example.com/terms
```

### Admin configuration overview
Admin → Discord focuses on:
- Bot/OAuth settings (application ID, public key, optional guild ID).
- Rights → Discord role mapping (portal rights are authoritative).
- Channel topology (recommended: a single restricted hauling channel with threads per request).

Recommended configuration: create a private hauling channel accessible to haulers only, and let the bot create threads per request inside it.

Admin → Webhooks is the dedicated home for webhook routing and delivery settings.

### Outbox Error Help
Admin → Discord → Outbox shows a Help ▸ toggle for failed deliveries. The help panel is driven by
`App\Services\DiscordOutboxErrorHelp::playbooks()` and the error normalization in
`DiscordOutboxErrorHelp::normalize()`.

To add a new playbook, create a new error key in `playbooks()` and update `selectErrorKey()` to map the
normalized HTTP status/Discord error code/message to that key.

Expected responses:
- `GET /api/discord/interactions/health` returns `200 {"ok":true}`.
- `POST /api/discord/interactions/` without a signature returns `401 signature_missing`.

A `500` response indicates a server error that must be fixed.
Double-check the URL is exact (including the trailing slash) and then review server logs for the error.

### Slash Commands
Available commands:
- `/open` — List open hauling contracts.
- `/request` — Lookup request status by ID or code.
- `/link` — Link your Discord user to a portal account using a one-time code.
- `/myrequests` — Show your last 5 requests (requires Discord ↔ account link).
- `/rates` — Snapshot of current rate plans.
- `/help` — Quick usage hints.
- `/ping` — Bot health check.

#### `/open` filters and pagination
Examples:
- `/open priority:high`
- `/open min_volume:5000 max_volume:120000`
- `/open min_reward:25000000 pickup_system:Jita drop_system:Amarr`
- `/open limit:5 page:2`

Pagination notes:
- `/open` returns results ordered by priority (high → normal), then oldest first, with deterministic IDs for stability.
- Use `limit` (max 10) and `page` to move through results.
- The Prev/Next/Refresh buttons keep filters intact and are rate-limited to prevent spam.

### How to link Discord account
1) Sign in to the hauling portal (EVE SSO).
2) Open **My Contracts** and find the **Discord Account Linking** card.
3) Click **Generate link code** and copy the one-time code (expires in 10 minutes).
4) In Discord, run `/link <code>` in the server with the hauling bot.
5) You can confirm the link status back on the portal, or unlink from the portal/admin page.

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
