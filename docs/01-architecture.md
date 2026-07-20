# 01 — Architecture Note

> Milestone 1 deliverable: "Short architecture note explaining the selected
> database, queue driver, credential storage approach, and module extensibility."

## 1. High-level shape

```
External SaaS ──HTTP(S) webhook──▶  Webhook Intake (signed, workspace-scoped)
                                          │
                                          ▼
                                   Payload Log (raw + parsed, status)
                                          │  field mapping + value rules
                                          ▼
                                   Execution Job (queued)
                                          │
                       ┌──────────────────┼──────────────────┐
                       ▼                   ▼                  ▼
                 Approval hold?      Module execution   Retry / failed
                 (Approval Queue)   (Connector action)  handling
                                          │
                                          ▼
                                   Result + Audit Trail
```

Every step writes to logs and (for sensitive actions) the audit trail. Everything
is bound to a **workspace**.

## 2. Layered structure

Kept as a modular monolith (single Laravel app), organized by domain so future
extraction is cheap:

- **HTTP layer** — controllers (admin panel + webhook intake), form requests,
  middleware (auth, workspace scope, permission gate).
- **Domain services** — `app/Services/*` for connectors, modules, routing, mapping,
  execution, approvals. Controllers stay thin.
- **Module Contract layer** — a PHP interface (`Contracts\ModuleContract`) + a DB
  registry row. Modules are discovered/registered, not hardcoded into the core.
- **Jobs layer** — `app/Jobs/*` queued jobs backed by the database queue.
- **Persistence** — Eloquent models with a `BelongsToWorkspace` trait (global scope).

Suggested namespaces:

```
app/
  Contracts/         # ModuleContract, ConnectorDriver, etc.
  Models/            # Workspace, Connector, Module, WebhookPayload, ExecutionJob, ...
  Services/          # ConnectorService, MappingService, ExecutionService, ...
  Jobs/              # ProcessWebhookPayload, RunExecutionJob, ...
  Modules/           # concrete module implementations (contract-driven)
  Support/           # Encryption/masking helpers, traits
  Http/
    Controllers/Admin/
    Controllers/Webhooks/
    Middleware/      # Authenticate, EnsureWorkspace, EnsurePermission
    Requests/
```

## 3. Database — **MySQL**

Chosen over the Laravel default SQLite because the workload is relational,
multi-tenant, and concurrent (queue workers + web + webhook intake writing at once),
needs JSON columns for flexible schemas (module I/O, payloads, flow definitions),
and MySQL matches the intended production target.

- Dev connection: `automation_app` on `127.0.0.1:3306`.
- JSON columns used for open-ended structures (schemas, payloads, flow definitions,
  audit changes) so modules extend without migrations.
- Foreign keys + indexes on `workspace_id` and status columns.

Full schema: [02-database-schema.md](02-database-schema.md).

## 4. Queue — `database` driver

- Baseline uses Laravel's `database` queue (`jobs`, `job_batches`, `failed_jobs`
  tables already migrated) — zero extra infra for the MVP, survives restarts, easy
  to inspect from the admin panel. Redis is the documented upgrade path (config is
  already present in `.env`) with no application code changes.
- **Two distinct concepts, do not conflate:**
  - `jobs` / `failed_jobs` — Laravel's internal queue plumbing.
  - `execution_jobs` — our **domain** record of an execution (status, input, result,
    error, attempts) shown in the admin UI. A queued `RunExecutionJob` updates its
    `execution_jobs` row as it progresses.
- Workers: `php artisan queue:work --tries=3 --backoff=...`. Retries are also
  triggerable manually from the admin panel (re-dispatch + increment attempts).

## 5. Credential storage

- API keys / tokens / secrets are stored in a dedicated `connector_credentials`
  table using Laravel's `encrypted` cast (AES-256-CBC via `APP_KEY`). No plaintext
  at rest beyond the encrypted blob.
- **Masking**: values are never returned to views in clear. A `masked()` accessor
  returns e.g. `••••••7K9A`. Edit forms show a "leave blank to keep" pattern rather
  than pre-filling the secret.
- Secrets are stripped from logs (log middleware + `$hidden` on models). Webhook
  signing secrets follow the same rules.
- Rotation is supported by versioning credential rows (`expires_at`, `rotated_at`).

## 6. Authentication — **custom**

Per client requirement, **no auth starter kit**. We implement:

- A `web` session guard with a custom `LoginController` (rate-limited),
  bcrypt-hashed passwords, "remember me", CSRF on all forms.
- A seeded initial admin user (M1).
- Session driver `file` in dev; recommend `database` or `redis` in production.

and [03-permissions-model.md](03-permissions-model.md).

## 7. Authorization — custom RBAC, workspace-scoped

- `roles`, `permissions`, `permission_role` tables + a `workspace_user` pivot that
  carries the user's role **within a workspace**.
- A `super_admin` flag on the user grants global access (bypasses workspace scope).
- Enforced with a Laravel Gate/policy layer plus an `EnsurePermission` middleware so
  restricted actions are blocked in **both** UI and backend requests.

## 8. Module extensibility strategy

The core must never need editing to add a connector/module:

1. A module implements `ModuleContract` (declares `name`, `type`, `actions`,
   `inputSchema`, `outputSchema`, `execute()`, `scopes`, `healthCheck()`).
2. It is registered in the `modules` registry table (metadata + JSON schemas + health).
3. Routing/mapping/flows reference modules **by slug**, never by concrete class.
4. Execution resolves the module from the registry at runtime.

This keeps the door open for the later public SDK, marketplace, and third-party
modules without a core rewrite.

## 9. Deployment assumptions (MVP)

- Single app server + MySQL; at least one always-on `queue:work` process
  (supervisor/systemd) and a scheduler (`schedule:run` cron) if needed.
- `.env`-driven config; `php artisan migrate --force` on deploy; `config:cache` +
  `route:cache` in production. No undocumented manual steps (see setup doc).
