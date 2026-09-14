# Deployment runbook

This is the release-gate runbook for CargoFlow, written at the end of Phase 10.
It targets a single Linux application server plus managed MySQL/Redis — the
smallest topology that supports every feature built across all ten phases
(realtime scan/dispatch boards, queued webhooks/notifications, a scheduler).
Scale out the web/queue tiers horizontally later; nothing here assumes a
single process.

## 1. Requirements

| Component | Requirement | Why |
|---|---|---|
| PHP | 8.3+ (project developed against 8.4) with `ext-pcntl`, `ext-posix`, `ext-redis` or `predis` | Horizon requires `pcntl`/`posix` (Unix-only — this is why Horizon could never run on the Windows dev machine; it runs normally here) |
| MySQL | 8.0+ | `SELECT ... FOR UPDATE` row locking (`NumberSequenceService`), JSON columns (manifests, audit logs) |
| Redis | 6+ | Queue (`QUEUE_CONNECTION=redis`), cache (`CACHE_STORE=redis`), Horizon's own metrics storage |
| Node.js | 20+ | Frontend build only (Vite) — not needed at runtime once `npm run build` has produced `public/build` |
| Web server | nginx or Apache in front of PHP-FPM | Standard Laravel document-root-is-`public/` setup |
| A process supervisor | systemd (or Supervisor) | Horizon and Reverb are long-running processes, not one-shot commands |

No S3/object storage is required to run the app: `FILESYSTEM_DISK=local` is a
deliberate Phase 6 decision (private customs/POD documents live on local
disk under opaque UUID filenames). If the app is scaled to more than one web
node, the `local` disk must move to shared storage (NFS/EFS) or S3 — see
§7.

## 2. Environment configuration

Copy `.env.example` to `.env` and set, at minimum:

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` (generate with `php artisan key:generate`), `APP_URL` (the real HTTPS origin).
- `DB_*` — a dedicated low-privilege MySQL user/database, not `root` (see `docs/AGENT_HANDOFF.md` for why `caching_sha2_password` matters on some PHP/MySQL driver combinations).
- `REDIS_*` — point at the managed Redis instance; `REDIS_PASSWORD` if it isn't wide open.
- `BROADCAST_CONNECTION=reverb`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` — all three are load-bearing, not defaults to leave alone. Several domain events (`PackageScanned`, `DeliveryAttemptRecorded`) broadcast **synchronously** (`ShouldBroadcastNow`), so the app server must always be able to reach the Reverb server — see §7 for what happens if it can't.
- `REVERB_APP_ID`/`REVERB_APP_KEY`/`REVERB_APP_SECRET` — generate real values (`php artisan reverb:install` or by hand), never reuse the local dev values committed to `.env.example`.
- `REVERB_HOST`/`REVERB_PORT`/`REVERB_SCHEME` — the **public** address browsers connect to (e.g. `wss://ws.example.com`, port 443 behind a reverse proxy terminating TLS in front of Reverb).
- `REVERB_SERVER_HOST=0.0.0.0`, `REVERB_SERVER_PORT` — the address the Reverb **process itself** binds, usually different from the public one above once a reverse proxy is in front of it.
- `REVERB_ALLOWED_ORIGINS` — **bare hostnames only** (`app.example.com`, not `https://app.example.com`). Reverb's origin check strips scheme/port before comparing; a full URL here silently rejects every real browser connection with no error beyond a Pusher code 4009 in the console. This exact mistake cost real debugging time in Phase 9 — see `docs/phases/PHASE_09.md`.
- `VITE_REVERB_*` — mirror the public `REVERB_*` values; these get baked into the built frontend bundle at `npm run build` time, so re-build after changing them.
- `MAIL_*` — a real transactional mail provider (`ShipmentDeliveredNotification`, `InvoiceIssuedNotification`, `WebhookDeliveryFailedNotification`, invitation emails all send mail).
- `SESSION_DRIVER=database`, `SESSION_ENCRYPT=true` in production.
- `SEED_DEFAULT_PASSWORD` — only relevant if seeding demo data (§5); never leave the sample value in a real deployment.
- `SEED_DEMO_DATA` — leave unset (false) in production. See §5.

## 3. Build and deploy steps

Run from the project root on the target server (or in a CI build stage that
produces an artifact to deploy):

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan storage:link
```

Notes:

- `--no-dev` excludes Pint/PHPUnit/Faker from the production autoloader; never run `composer install --no-dev` on a machine you still intend to run `php artisan test` on.
- `migrate --force` is required outside `APP_ENV=local`/`testing` — Laravel refuses to run migrations against a non-local environment without it.
- `config:cache`/`route:cache`/`event:cache` are safe here because this app resolves no config from a request-time closure that isn't already cache-safe (no `env()` calls outside `config/*.php`, per Laravel convention followed throughout all ten phases). **Re-run all three after every `.env` change** — a cached config silently ignores new environment values, which is the single most common "why isn't my change taking effect in production" mistake.
- There is no separate asset-versioning step beyond Vite's own manifest (`public/build/manifest.json`) — Laravel's `Vite` facade reads it directly, no extra cache-bust configuration needed.

## 3a. First run: create the superadmin

There is no installer and no shell step. A fresh production deploy has no
accounts at all, so the site handles it itself:

1. The deploy runs `php artisan migrate --force` (§3) as usual. Nothing else
   is needed — do **not** add `db:seed` to the GitHub workflow for this.
2. Open the site. While no superadmin exists, every page redirects to
   **`/setup`** (API/JSON requests get a `503` instead).
3. Enter a name, email and password (12+ characters, letters and numbers).
   The account is created as the platform superadmin, signed in, and sent to
   `/superadmin`. The permission catalog and super-admin role are created at
   the same moment if the deploy never seeded them.
4. `/setup` then returns **404 permanently**. Further admins are invited from
   Settings → Platform admins.

**Do step 3 immediately after the first deploy.** The setup page asks for no
proof of server ownership, so until it is completed, the first person to
reach the site — including automated scanners that watch for new domains —
can create the superadmin and control every workspace. If you cannot open the
site right away, keep it unreachable (firewall, basic auth at the proxy, or a
private URL) until you have.

Details worth knowing:

- Setup stays closed once any platform admin has ever existed. Suspending or
  soft-deleting every admin does **not** reopen it — recover access with a
  password reset or directly in the database instead.
- Two people submitting at the same moment cannot both succeed: creation runs
  under a cache lock and re-checks the database inside it. That lock needs a
  shared cache (`CACHE_STORE=redis`, per §2) once there is more than one web
  node.
- Next, configure mail under Settings → Platform, or invitation emails will
  not be delivered.
- Code: `app/Services/Setup/InstallationService.php`,
  `app/Http/Middleware/RedirectToSetup.php`, `SetupController`, and
  `tests/Feature/Setup/FirstRunSetupTest.php`.

## 4. Long-running processes (systemd)

Three supervised processes are required beyond PHP-FPM/nginx. Example unit
files (adjust `User`, `WorkingDirectory`, and the PHP binary path):

**`/etc/systemd/system/cargoflow-horizon.service`** — queue worker supervisor (replaces `queue:work` from local dev; Horizon cannot run on Windows, which is why every prior phase's manual verification used `queue:work redis` instead — on a real Linux deploy, always run Horizon, not raw `queue:work`):

```ini
[Unit]
Description=CargoFlow Horizon
After=network.target redis.service

[Service]
User=www-data
WorkingDirectory=/var/www/cargoflow
ExecStart=/usr/bin/php artisan horizon
Restart=always
RestartSec=5
# Horizon needs a graceful stop, not a kill, so in-flight jobs finish:
ExecStop=/usr/bin/php artisan horizon:terminate
TimeoutStopSec=90

[Install]
WantedBy=multi-user.target
```

**`/etc/systemd/system/cargoflow-reverb.service`** — the realtime broadcast server:

```ini
[Unit]
Description=CargoFlow Reverb
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/cargoflow
ExecStart=/usr/bin/php artisan reverb:start --host=0.0.0.0 --port=8080
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Front Reverb with the reverse proxy for TLS/WebSocket upgrade (nginx example
location block, alongside the normal PHP-FPM `location /`):

```nginx
location /app/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
}
```

**The scheduler** — Laravel's own cron entry drives `routes/console.php`'s
two scheduled tasks (`poll-carrier-tracking` every 5 minutes,
`prune-failed-jobs` daily):

```cron
* * * * * cd /var/www/cargoflow && php artisan schedule:run >> /dev/null 2>&1
```

Horizon's dashboard is reachable at `/horizon` once deployed — it is gated by
`is_platform_admin` via `Horizon::auth(...)` in `app/Providers/AppServiceProvider.php`, the same platform-admin check every other platform-only surface in this app uses (failed jobs, act-as-company).

## 5. Demo data

**In production (`APP_ENV=production`), `db:seed` installs only the
permission catalog and system roles.** The sample companies are skipped,
because they include `admin@cargoflow.test` — a platform admin whose password
is `SEED_DEFAULT_PASSWORD`. On a live site that would be a known login to
every tenant, and it would also close `/setup` (§3a) before you reached it.
Set `SEED_DEMO_DATA=true` only for a deliberately public demo instance.

Outside production the behaviour below is unchanged:

`php artisan db:seed` (or `migrate --seed` on a fresh database) always runs
`AuthorizationSeeder` (permission catalog, system roles — required in every
environment) and `DatabaseSeeder`'s two-company skeleton (admin/branch/
warehouse/driver/vehicle per company). It also runs `DemoDataSeeder`, which
populates the `GLX` (Dubai) company with realistic customers, rate cards, and
four shipments spanning the full lifecycle (draft → received → at-customs →
delivered-and-paid) — see `database/seeders/DemoDataSeeder.php`.

**Do not run `db:seed` against a production database that already has real
tenant data** — `DatabaseSeeder`'s `updateOrCreate` calls are safe to
re-run, but `DemoDataSeeder` writes fabricated customers/shipments/invoices
under the `GLX` company code, which only makes sense on a demo/staging
instance. It guards against duplicating itself on repeat runs (checks for a
`GLX-DEMO-%` customer_number prefix first) but has no guard against running
against a real `GLX`-coded tenant, because production is not expected to
reuse that seed company code at all.

Demo seeding does not require Reverb or a queue worker to be running: it
temporarically switches the broadcast connection to `log` for its own
duration (several domain services broadcast synchronously — see §7) so a
completely fresh `migrate --seed` succeeds before any long-running process
has been started.

## 6. Rollback

Rehearsed and verified during Phase 10 (see `docs/phases/PHASE_10.md`):

```bash
php artisan down --render="errors::503" --retry=60
php artisan migrate:rollback   # rolls back the most recent batch
# ... deploy the previous release artifact ...
php artisan config:cache
php artisan up
```

`migrate:rollback` with no `--step` rolls back exactly the most recent
migration batch — safe for a same-day rollback of the release that was just
deployed, not for reverting several releases at once (pass `--step=N` or
`--batch=N` deliberately if more history needs to unwind). Every migration in
this codebase has a working `down()` method; the Phase 10 rehearsal rolled
back all 52 migrations across all ten phases cleanly and re-applied them
without error.

If a bad deploy already wrote data under the new schema before rollback,
restore from a database backup (§7) instead of rolling back migrations —
`migrate:rollback` reverts schema, not data, and a `down()` that drops a
column will not un-drop the values that were already written to it.

## 7. Backups

No backup infrastructure exists in this environment to test against, so this
section is guidance for the deploying team, not a rehearsed procedure like
§6.

- **Database**: nightly `mysqldump` (or the managed database's native
  snapshot feature, if using RDS/Cloud SQL/etc. — prefer that over `mysqldump`
  where available, since it doesn't hold locks or lag on a growing
  `tracking_events`/`audit_logs`/`package_scans` table). Retain at least 30
  days, matching the `prune-failed-jobs` retention already chosen for
  `failed_jobs` in `routes/console.php`. Test the restore path at least
  quarterly — an untested backup is not a backup.
- **Private documents**: the `local` disk (customs documents, POD
  signatures/photos, `config/filesystems.php`) is not covered by a database
  backup at all. Include `storage/app/private` (or wherever `FILESYSTEM_DISK`
  points) in the same backup cadence as the database, or migrate to S3 with
  versioning/lifecycle rules if the deployment scales beyond one web node
  (a database backup without the matching document files is incomplete —
  `documents` rows will point at files that no longer exist after a restore).
- **`.env` and Reverb/webhook secrets**: back up `APP_KEY` and the webhook
  HMAC secrets (`webhook_endpoints.secret`, see `docs/WEBHOOKS.md`)
  separately from the database dump, ideally in a secrets manager rather than
  a file — losing `APP_KEY` makes every encrypted column and signed URL
  (invitation links, `/broadcasting/auth`) permanently unreadable, and losing
  a webhook secret means every subscriber must be re-configured.
- **Point-in-time recovery**: if the managed database supports binlog-based
  PITR, enable it — several tables here are intentionally immutable
  (`tracking_events`, `customs_clearance_events`, `payments`,
  `delivery_attempts`, `audit_logs`) specifically so they double as an audit
  trail; PITR is the only way to recover from an operational mistake that
  bypassed those write paths (e.g. a bad manual `DB::table(...)->delete()`),
  since the application layer never exposes a way to undo them.

## 8. Health checks and monitoring

- `/up` — Laravel's built-in health-check endpoint (`bootstrap/app.php`'s
  `health: '/up'`), returns 200 with no auth required. Point a load
  balancer's health check here.
- `/horizon` — queue depth, throughput, and failed-job counts, gated to
  platform admins.
- `/settings/failed-jobs` — this app's own failed-jobs viewer (Phase 10),
  gated to platform admins, for retrying/dismissing individual jobs without
  shelling into the server. `queue:prune-failed` (daily, §4) keeps this from
  growing forever.
- `/settings/audit-log` — company-scoped audit trail for every mutating
  action, gated by the `audit-logs.view` permission.
- Watch Reverb connectivity specifically: because `PackageScanned` and
  `DeliveryAttemptRecorded` broadcast synchronously
  (`ShouldBroadcastNow` — a deliberate Phase 5/7 choice to avoid needing a
  queue worker for realtime updates), **a Reverb outage turns into visible
  HTTP failures** on warehouse scanning and POD-recording endpoints, not a
  silently-dropped broadcast. Alert on Reverb process health directly (the
  systemd unit's `Restart=always` limits the outage window, but a monitoring
  check against the public Reverb origin is still worth having independent
  of that).

## 9. Zero-downtime notes

This is a single-server runbook, so "zero-downtime" here means "no dropped
requests during a deploy," not a full blue/green setup:

1. `php artisan down --retry=60` before migrating (browsers get a 503 with a
   `Retry-After` header rather than a broken half-migrated page).
2. Migrate, deploy the new code, `config:cache`/`route:cache`/`event:cache`.
3. `php artisan up`.
4. Restart Horizon (`systemctl restart cargoflow-horizon`) so workers pick up
   the new code — Horizon does not hot-reload; jobs in flight at restart
   time finish first because `ExecStop` calls `horizon:terminate`, not a
   hard kill.
5. Reverb does **not** need restarting for an ordinary code deploy (it
   doesn't run application code beyond broadcasting already-serialized
   payloads) — only restart it if `REVERB_*` env values themselves changed.

A true zero-downtime setup (rolling web nodes behind a load balancer, a
migration strategy that never breaks the previous release mid-rollout) is out
of scope for this runbook; the guardrail worth stating explicitly is: every
migration in this codebase is additive-then-backfill or has a safe `down()`,
never a same-migration column rename/type-change against a large table, so
the existing migration set is already rolling-deploy-friendly if that
topology is adopted later.
