# Automation — Integration & Automation Platform (iPaaS MVP)

**Automation** is an iPaaS-style integration & automation platform. External systems
send webhooks; the platform ingests, validates, maps, queues, and executes actions
against connected applications — with human approval gates, a full audit trail, and
multi-workspace isolation.

It is built as a **modular monolith** (a single Laravel app organized by domain) so
that later phases — public SDK, connector marketplace, third-party modules, a visual
flow canvas — can be added without rewriting the core.

---

## Status — all 6 milestones delivered

The build was scoped into 6 milestones (full plan & acceptance in
[docs/04-milestones.md](docs/04-milestones.md)):

| Milestone | Scope | Status |
|---|---|:--:|
| **M1** | Project setup, custom auth, admin shell, core migrations | ✅ |
| **M2** | Workspaces, users, roles & permissions (RBAC) | ✅ |
| **M3** | Connector registry, module registry, secure credentials | ✅ |
| **M4** | Webhook intake, payload logs, field mapping | ✅ |
| **M5** | Execution jobs, queues, retries, automation flows | ✅ |
| **M6** | Audit trail, security hardening, QA, handover | ✅ |

End-to-end flow working today: **webhook intake → payload log → field mapping →
execution job → queue → retry → logs → audit trail.**

---

## Stack

| Concern | Choice |
|---|---|
| Framework | Laravel `^13.8`, PHP `^8.3` |
| Database | **MySQL** (`automation_app`) |
| Queue | `database` driver (Redis is the documented upgrade path) |
| Cache / Session | `file` (dev) |
| Auth | **Custom** session guard — no Breeze/Jetstream/Fortify/Sanctum |
| Authorization | Custom RBAC (roles + permissions), workspace-scoped |
| Credentials | Laravel `encrypted` casts (AES-256 via `APP_KEY`), masked in UI |
| Frontend | Tailwind CSS v4 + Blade + Vite (dual dark/light theme) |
| Tests | PHPUnit `^12.5` (49 passing) · Lint: Laravel Pint |

---

## Feature overview

- **Custom authentication** — hand-built session guard: throttled login (5-attempt
  lockout), disabled-account block, session regeneration, "remember me",
  audit-logged login/logout. No auth starter kit.
- **Workspaces & RBAC** — every domain table is workspace-scoped via a
  `BelongsToWorkspace` global scope + `EnsureWorkspace` middleware. A user can belong
  to **multiple workspaces with a different role in each**; permissions and visible
  data follow the active workspace. 4 seeded roles (super admin, ops admin, reviewer,
  analyst). Every write route is gated by `can:<permission>` on the backend.
- **Connector registry** — external app connections with type/provider/role, health
  status, and a "run health check" action.
- **Module registry** — contract-driven (`App\Contracts\ModuleContract`). Modules
  declare name, type, actions, I/O schema, execution method, scopes and health, and
  are registered by **slug** — the core never references a concrete module class.
- **Secure credentials** — API keys/secrets stored with the `encrypted` cast, hidden
  from serialization, shown masked (`••••1234`), edited with "leave blank to keep".
- **Webhook intake** — public signed endpoint (`POST /webhooks/{slug}`) with
  constant-time HMAC verification; every request is logged (valid or invalid) with a
  clear status and error, never a 500.
- **Field mapping** — map incoming payload fields to canonical/target fields with
  value transforms (lowercase/uppercase/trim/default).
- **Execution jobs & flows** — accepted payloads trigger flows that create
  `execution_jobs` rows and dispatch a queued `RunExecutionJob`; status, input,
  result, error and attempts are tracked and visible; failed jobs are **retriable
  from the admin panel**.
- **Audit trail** — append-only `audit_logs` for login, user/connector/credential/
  webhook/mapping/flow/module changes and job retries. Secret values are never
  recorded.
- **Themed UI** — Tailwind v4 dual dark/light theme, reusable Blade components, and
  custom error pages (401/403/404/419/429/500/503).

---

## Quick start

Prerequisites: PHP 8.3+, Composer 2.x, Node 20+, MySQL 8.x. Full detail in
[docs/06-setup-and-handover.md](docs/06-setup-and-handover.md).

```bash
# 1. Dependencies
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate        # APP_KEY also encrypts stored credentials

# 3. Database — configure DB_* in .env, then create the schema
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS automation_app CHARACTER SET utf8mb4;"
php artisan migrate --seed      # runs migrations, seeds roles/users/workspaces/demo data

# 4. Build assets
npm run build

# 5. Run everything (server + queue worker + logs + vite)
composer run dev
```

Then open the app, sign in with a seeded account (below), and process the demo flow.

---

## Demo credentials (local/seed only)

All seeded users share the password **`password`** (local demo only). Details in
[docs/06-setup-and-handover.md](docs/06-setup-and-handover.md).

| Email | Role | Workspaces |
|---|---|---|
| admin@example.com | Super Admin | All (bypasses workspace scope) |
| ops@example.com | Ops Admin | Core Operations + Staging Sandbox |
| reviewer@example.com | Reviewer | Core Operations |
| analyst@example.com | Analyst (read-only) | Core Operations |
| multi@example.com | Ops Admin (Core) / Reviewer (Staging) | **Both — different role in each** |

Seeders are idempotent (`updateOrCreate` / `sync`) — safe to re-run.

---

## Common commands

```bash
composer run dev                   # server + queue listener + pail logs + vite
php artisan serve                  # app only
php artisan queue:work --tries=3   # process execution jobs (required for flows)
php artisan migrate --seed         # migrate + seed demo data
php artisan modules:sync           # sync code-defined modules into the registry
php artisan test                   # PHPUnit feature + unit tests
./vendor/bin/pint                  # format before committing
```

A queue worker must be running for webhook-triggered flows to execute. See
[docs/06-setup-and-handover.md](docs/06-setup-and-handover.md) for the supervisor
config used in production.

---

## Project structure

```
app/
  Contracts/                 # ModuleContract (module extensibility)
  Modules/                   # AbstractModule, ModuleRegistry, concrete modules
  Console/Commands/          # modules:sync
  Jobs/                      # RunExecutionJob (queued execution)
  Services/                  # MappingService, FlowRunner, ConnectorTester
  Http/Controllers/Auth/     # custom LoginController
  Http/Controllers/Admin/    # dashboard, workspaces, users, roles, connectors,
                             #   credentials, modules, webhooks, payloads, mappings,
                             #   flows, execution jobs (queue), audit
  Http/Controllers/Webhooks/ # WebhookIntakeController (public signed intake)
  Http/Middleware/           # EnsureWorkspace
  Models/                    # + Models/Concerns/BelongsToWorkspace
database/migrations/         # 17 migrations (see below)
database/seeders/            # DatabaseSeeder, RolesAndPermissionsSeeder
resources/views/             # Blade views + Tailwind v4 dual-theme components + errors/
docs/                        # architecture, schema, permissions, milestones, UI,
                             #   setup, git workflow, security checklist
```

**Domain tables:** `workspaces`, `connectors`, `modules`, `audit_logs`, `roles`,
`permissions`, `workspace_user`, `connector_credentials`, `webhook_endpoints`,
`webhook_payloads`, `field_mappings`, `flows`, `execution_jobs` (+ Laravel's
`users`, `cache`, `jobs`).

---

## Documentation

| Doc | Contents |
|---|---|
| [docs/00-overview.md](docs/00-overview.md) | Product vision & glossary |
| [docs/01-architecture.md](docs/01-architecture.md) | Architecture note (DB, queue, credentials, module contract) |
| [docs/02-database-schema.md](docs/02-database-schema.md) | Full DB schema & Module Contract |
| [docs/03-permissions-model.md](docs/03-permissions-model.md) | Roles/permissions model |
| [docs/04-milestones.md](docs/04-milestones.md) | Milestone plan & acceptance tracking |
| [docs/05-ui-theme.md](docs/05-ui-theme.md) | Theme/UI integration |
| [docs/06-setup-and-handover.md](docs/06-setup-and-handover.md) | Setup, queue workers, env, walkthrough |
| [docs/07-git-workflow.md](docs/07-git-workflow.md) | Branch/commit workflow |
| [docs/08-security-checklist.md](docs/08-security-checklist.md) | Security review & fixes |

---

## Security highlights

- Custom session auth only — no auth starter kit.
- Everything is workspace-scoped; domain queries never cross workspaces.
- Authorization enforced on the **backend** (middleware + gates); UI `@can` is
  convenience only.
- Secrets use `encrypted` casts, are in `$hidden`, masked in views, and scrubbed
  from audit logs — never rendered in plaintext.
- CSRF on every state-changing form; webhook intake is HMAC-verified and CSRF-exempt.
- Append-only audit trail for sensitive actions.

Full checklist: [docs/08-security-checklist.md](docs/08-security-checklist.md)
