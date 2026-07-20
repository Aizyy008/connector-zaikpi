# 06 — Setup & Handover

> Milestone 1 & 6 deliverable. Goal: another developer can run and continue the
> project with **no undocumented manual steps**.

## Prerequisites

- PHP **8.3+** (verified: 8.3.30) with extensions: `pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `ctype`, `json`.
- Composer 2.x
- Node **20+** (verified: 24.x) + npm
- MySQL 8.x running locally

## First-time setup

```bash
# 1. Install dependencies
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate      # sets APP_KEY (also the credential encryption key)

# 3. Configure the database in .env
#    DB_CONNECTION=mysql
#    DB_DATABASE=automation_app
#    DB_USERNAME=root
#    DB_PASSWORD=
#    (create the schema first)
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS automation_app CHARACTER SET utf8mb4;"

# 4. Migrate + seed (creates demo admin, roles, workspaces, demo connectors,
#    and syncs the code-defined modules into the registry)
php artisan migrate --seed

# (adding a module later? implement ModuleContract, list it in config/modules.php, then:)
php artisan modules:sync

# 5. Build front-end assets
npm run build      # or: npm run dev
```

> ⚠️ `APP_KEY` encrypts stored credentials. If it is rotated, existing
> `connector_credentials` values become undecryptable. Back it up; document rotation.

## Running (development)

```bash
composer run dev
```
Runs, concurrently: `php artisan serve`, `queue:listen`, `pail` (logs), and `vite`.

Or individually:
```bash
php artisan serve
php artisan queue:work --tries=3      # required for execution jobs
npm run dev
```

## Environment variables that matter

| Var | Value / note |
|---|---|
| `APP_KEY` | Required. Encrypts credentials. |
| `DB_*` | MySQL `automation_app`. |
| `QUEUE_CONNECTION` | `database` (baseline). Redis is the upgrade path. |
| `SESSION_DRIVER` | `file` (dev); use `database`/`redis` in prod. |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` on servers. |
| `APP_DISPLAY_TIMEZONE` | Timezone shown in the admin panel (default `Europe/Athens`). Data is always stored in UTC and converted on display. |

## Permanent queue worker (production)

A worker must run **continuously** so queued `RunExecutionJob`s are processed.
Running `php artisan queue:work` by hand over SSH stops when the terminal closes —
use one of the persistent options below (pick the one your server supports). Replace
`/var/www/automation` and `php` with your real project path and PHP binary.

### Option A — Supervisor (recommended where available)

```ini
# /etc/supervisor/conf.d/automation-worker.conf
[program:automation-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/automation/artisan queue:work --tries=3 --sleep=1 --max-time=3600
autostart=true
autorestart=true
stopwaitsecs=3600
numprocs=1
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/automation/storage/logs/worker.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start automation-worker:*
sudo supervisorctl status            # verify RUNNING
```

### Option B — systemd (root servers without Supervisor)

```ini
# /etc/systemd/system/automation-worker.service
[Unit]
Description=Automation queue worker
After=network.target mysql.service

[Service]
User=www-data
Restart=always
RestartSec=3
WorkingDirectory=/var/www/automation
ExecStart=/usr/bin/php /var/www/automation/artisan queue:work --tries=3 --sleep=1 --max-time=3600

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now automation-worker
sudo systemctl status automation-worker
```

### Option C — Plesk

Plesk has no Supervisor UI, so use **one** of:

1. **systemd** (if you have root/SSH) — use Option B above. Best choice on a Plesk VPS/dedicated server.
2. **Plesk Scheduled Task (cron)** — for shared/no-root Plesk. Under
   *Websites & Domains → Scheduled Tasks*, add a task that runs **every minute** with
   a short-lived, self-terminating worker (a lock stops overlap):

   ```bash
   /opt/plesk/php/8.3/bin/php /var/www/vhosts/YOURDOMAIN/httpdocs/artisan queue:work --stop-when-empty --max-time=55 --tries=3
   ```

   `--stop-when-empty` exits when the queue drains and `--max-time=55` guarantees it
   ends before the next minute, so at most one worker runs at a time. (Adjust the PHP
   path/version to your Plesk PHP handler.)

After **every deploy**, reload the worker so it picks up new code:

```bash
php artisan queue:restart      # Supervisor/systemd auto-respawn with fresh code
```

Verify the worker is alive: send a test webhook (see the walkthrough below) and
confirm the execution job moves from **pending → completed** without running
`queue:work` manually.

### Execution jobs (Milestone 5)

Accepted webhook payloads that match an **active flow** are turned into
`execution_jobs` rows and dispatched as `RunExecutionJob` onto the queue. A worker
must be running for them to process:

```bash
php artisan queue:work --tries=3        # dev: composer run dev already includes queue:listen
```

- Job status lives in the domain `execution_jobs` table (admin: **Queue & Logs**),
  not Laravel's internal `jobs`/`failed_jobs`.
- Failed jobs are retried from the admin panel (**Queue & Logs → Retry**), which
  re-dispatches `RunExecutionJob` for that row.
- Without a running worker, jobs simply stay `pending` until one starts — nothing is
  lost. In tests `QUEUE_CONNECTION=sync` runs them inline.

## Production deployment

### 0. Web server document root — **must point at `/public`**

The web server's document root must be Laravel's `public/` directory, e.g.
`/var/www/automation/public`. Never serve the project root — doing so exposes
`.env`, `storage/`, and source. (Apache: `DocumentRoot .../public` + `AllowOverride All`;
Nginx: `root .../public;` with the standard Laravel `try_files` block.)

### 1. Build & deploy front-end assets (fixes "raw, unstyled HTML")

The admin panel loads its CSS/JS through Vite. In production it must use the
**compiled build**, not a dev server.

```bash
# a) Remove any dev-server marker. If public/hot exists, Laravel points asset URLs
#    at the Vite dev server (http://[::1]:5173) and the live site loads no CSS/JS.
rm -f public/hot

# b) Produce the production build (compiled CSS/JS + manifest).
npm ci
npm run build

# c) Verify the build landed. These MUST exist and be deployed with the app:
ls public/build/manifest.json          # the manifest @vite() reads
ls public/build/assets/                 # hashed CSS/JS + fonts
```

> `public/build/` and `public/hot` are gitignored, so a git-only deploy ships **no
> compiled assets**. Always run `npm run build` on the server (or ship `public/build/`
> from CI). If the page renders unstyled, check for a stray `public/hot` first.

### 2. Application deploy steps

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart          # reload workers with new code
```

### 3. Clear caches after any change

```bash
php artisan optimize:clear         # config, route, view, cache, compiled
```

A quick one-shot production refresh:

```bash
rm -f public/hot && npm ci && npm run build \
  && php artisan migrate --force \
  && php artisan optimize:clear && php artisan optimize \
  && php artisan queue:restart
```

## Seed / demo data (delivered)

- Admin (super admin) + ops admin + reviewer + analyst accounts.
- 2 workspaces (`core-operations`, `staging-sandbox`).
- ≥2 demo connectors and ≥2 demo modules with health/status.
- Demo webhook endpoint + sample payloads + example field mappings.
- Demo flow demonstrating webhook → execution job.

Demo credentials are listed in the private handover note (not committed).

## Testing

```bash
php artisan test          # feature + unit
./vendor/bin/pint         # format
```

## Future extension points (do not block)

- **Connectors/modules**: add by implementing `ModuleContract` + a registry row.
- **Queue**: swap `database` → `redis` via `.env` only.
- **Auth**: session guard is custom; SSO/2FA can layer on without replacing it.
- **Frontend**: component-based Blade + tokens; a future SPA/visual canvas can reuse
  the same API + palette.

## Demo credentials (local/seed only)

All seeded users share the password **`password`** (local demo only — never ship
these to a real environment). Assigned in `core-operations`; `ops` is also in
`staging-sandbox`.

| Email | Role | Notes |
|---|---|---|
| admin@example.com | Super Admin | Full access; sees all workspaces |
| ops@example.com | Ops Admin | Connectors, webhooks, flows, queue, approvals |
| reviewer@example.com | Reviewer | Approvals + read-only audit (Core only) |
| analyst@example.com | Read-only Analyst | View dashboards/logs (Core only) |

## End-to-end walkthrough (the full MVP flow)

1. Log in as `admin@example.com`.
2. **Connectors** — CommerceApp / BusinessApp with encrypted credentials + health.
3. **Webhooks** — the `commerceapp-orders` endpoint (copy its URL + secret via Rotate).
4. Send a signed order (worker running: `php artisan queue:work`):

   ```bash
   BODY='{"order_number":"DEMO-1","customer":{"email":"A@B.com"},"currency":"eur","total":50}'
   SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')
   curl -X POST http://localhost:8000/webhooks/commerceapp-orders \
     -H "Content-Type: application/json" -H "X-Signature: $SIG" -d "$BODY"
   ```

5. **Payload Logs** — the payload appears; inspect raw/parsed + live mapping preview.
6. **Mappings** — the order field mappings that drive the preview.
7. **Flows** — the active "Paid order → Create invoice" flow that fired.
8. **Queue & Logs** — the generated execution job (completed); retry the seeded
   failed job to see re-dispatch.
9. **Audit Trail** — every step above recorded (login, webhook.received, execution…).

## Handover artifacts (M6)

- [x] Source code + migrations + seeders
- [x] `.env.example` complete and accurate (tracked in the repo root)
- [x] Setup doc (this file) + architecture note + schema + permission model (`docs/`)
- [x] Project onboarding `CLAUDE.md` and coding rules (`.claude/rules/`) included in the package
- [x] Security checklist + fixes summary ([08-security-checklist.md](08-security-checklist.md))
- [x] Demo credentials/data (below + seeders)
- [ ] Final walkthrough video (intake → mapping → job → queue → retry → logs → audit) — **record from a live install** using the end-to-end walkthrough above; not committed to the repo

> All files referenced by the documentation (`.env.example`, `CLAUDE.md`,
> `.claude/rules/*`, every `docs/*`) are present in the delivered source. Build the
> assets on deploy (see **Production deployment §1**) and point the document root at
> `public/`.
