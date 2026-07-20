# 04 — Milestone Plan & Acceptance Tracking

Source of truth for scope and "done". Update the **Status** column as work lands.
General rule: each milestone ships **functional code** — migrations, UI, demo/seed
data, docs, and a short walkthrough — not screenshots alone. Fix review issues
before starting the next milestone.

Legend: ⬜ not started · 🟡 in progress · ✅ accepted

---

## M1 — Project Setup, Architecture & Admin Foundation  🟡

**Scope:** Laravel base, env/config, repo structure, DB connection, queue baseline,
deployment assumptions. Admin panel shell with **custom** auth + protected routes.
Initial migrations for core entities (users, workspaces, connectors, modules, jobs,
logs, audit). Architecture note.

**Deliverables:** login-protected admin area · initial migrations + seeded demo admin ·
setup doc · walkthrough video.

**Acceptance:**
- [x] Admin can log in and reach the protected panel. *(custom session guard, verified end-to-end)*
- [x] Runs locally/server with **no undocumented manual steps**. *(see setup doc; `.env.example` aligned to MySQL)*
- [x] Core migrations run from a clean DB. *(verified via `migrate:fresh --seed`)*
- [x] Architecture does not hardcode future modules (contract-driven registry).
- [ ] Walkthrough video recorded *(pending)*

**Implemented:** migrations (users +admin fields, workspaces, connectors, modules,
audit_logs) · models · `LoginController` (throttled, status-checked, audit-logged) ·
Tailwind v4 dual-theme shell (`x-app-layout`, `x-card`, `x-stat-card`, `x-theme-toggle`) ·
protected `admin/*` routes · seeded 2 workspaces + 4 role-representative users.

Docs: [01-architecture.md](01-architecture.md), [06-setup-and-handover.md](06-setup-and-handover.md)

---

## M2 — Workspaces, Users, Roles & Permissions  🟡

**Scope:** Workspace CRUD · user management + role assignment + workspace association ·
custom RBAC (admin/operator/viewer-style) · workspace-level authorization checks.

**Deliverables:** workspace UI · user/role/permission UI · seed data for access levels ·
permission-model doc.

**Acceptance:**
- [x] User sees only their workspace's resources. *(BelongsToWorkspace global scope + membership; test-covered)*
- [x] Restricted actions blocked in **UI and backend**. *(`can:` route middleware + `@can`; feature tests assert 403 on backend)*
- [x] ≥3 access levels demonstrable. *(Super Admin / Ops Admin / Reviewer / Analyst seeded)*
- [x] No critical admin route reachable without auth. *(all `admin/*` behind `auth`+`workspace`)*
- [ ] Walkthrough video recorded *(pending)*

**Implemented:** migrations (roles, permissions, permission_role, workspace_user) ·
RBAC models + `BelongsToWorkspace` trait + `WorkspaceContext` · `EnsureWorkspace`
middleware + per-request workspace switcher · `Gate::before` super-admin bypass +
per-slug gates from a static `Permissions` catalog · Workspace CRUD, User CRUD
(role assignment), full Role management (create/edit/delete + editable permission
matrix, gated by `roles.manage`) · seeded 4 roles + 34 permissions + memberships
(Reviewer/Analyst limited to Core to demo isolation) · 12 authorization/role
feature tests (14 total, all green).

Docs: [03-permissions-model.md](03-permissions-model.md)

---

## M3 — Connector Registry, Module Registry & Secure Credentials  🟡

**Scope:** connector registry screens · module registry via Module Contract (name,
type, actions, I/O schema, execution method, scopes, health, logs) · encrypted
credential storage + masking · connector/module health-check indicators.

**Deliverables:** connector registry UI + schema · module registry UI + schema ·
credential storage + masking · ≥2 demo connector/module records.

**Acceptance:**
- [x] Credentials never shown in plaintext after save. *(encrypted cast; masked display; `$hidden`; test asserts raw DB ≠ plaintext)*
- [x] Connector/module can be created, edited, disabled, viewed. *(connector CRUD; module view + enable/disable)*
- [x] Module structure extends without core rewrite. *(ModuleContract + config registry + `modules:sync`)*
- [x] Health/status fields visible and testable. *(ConnectorTester + module healthCheck; status badges)*
- [ ] Walkthrough video recorded *(pending)*

**Implemented:** `connector_credentials` (AES `encrypted` cast + masking + leave-blank
edit) · Connector CRUD + health check (ConnectorTester) · `ModuleContract` interface
+ `AbstractModule` + 2 builtin modules + `config/modules.php` + `ModuleRegistry` +
`modules:sync` command · Module registry UI (list/detail, enable/disable, health,
sync) · route-binding workspace scoping (`resolveRouteBinding`) closing a
cross-workspace access gap · 13 feature tests (27 total, all green).

Docs: [02-database-schema.md](02-database-schema.md) (Module Contract)

---

## M4 — Webhook Intake, Payload Logs & Field Mapping  🟡

**Scope:** secure webhook endpoints (JSON) · store payloads with metadata + status ·
raw + parsed inspection screen · field mapping logic · validation + clear error
handling.

**Deliverables:** webhook endpoint(s) · payload log screen (raw+parsed) · field
mapping UI + schema · demo payloads + mappings.

**Acceptance:**
- [x] Test webhook appears in payload logs. *(live signed POST → 202 → logged; verified)*
- [x] Invalid payloads stored/reported with clear error status. *(malformed → 422 stored invalid; bad signature → 401 stored invalid)*
- [x] Admin can create/update field↔internal mappings. *(FieldMapping CRUD + live mapping preview on payloads)*
- [x] Payload logs linked to correct workspace + connector/module. *(endpoint carries workspace+connector; scoped access, test-covered)*
- [ ] Walkthrough video recorded *(pending)*

**Implemented:** `webhook_endpoints` (encrypted+hidden HMAC secret, rotate-once
reveal) · `webhook_payloads` (raw + parsed + status + error + headers) ·
`field_mappings` (dot-path source→target + transforms) · public CSRF-exempt
intake with HMAC-SHA256 verification, JSON validation, and always-logged payloads ·
`MappingService` (dot-path apply + transforms + missing-field report) · admin UI:
endpoints CRUD, payload logs (filter + raw/parsed/mapping-preview inspector),
mappings CRUD · seeded endpoint + 4 mappings + valid/invalid demo payloads ·
10 feature tests (37 total, all green).

---

## M5 — Execution Jobs, Queues, Retries & Basic Automation Flows  🟡

**Scope:** execution job records (status/input/result/error/timestamps) · Laravel
queue processing · failed-job handling + retry from admin · basic flow logic
(trigger/mapping/action/status/history) · queue/job monitoring screens.

**Deliverables:** execution job schema + UI · queue worker notes · failed/retry
screen · basic flow screen · demo webhook→job flow.

**Acceptance:**
- [x] Received webhook generates an execution job. *(FlowRunner matches active flow → ExecutionJob; verified live)*
- [x] Job processes through queue → completed/failed. *(database queue + `queue:work` → completed; verified live, not sync)*
- [x] Failed executions retriable from admin. *(Queue & Logs → Retry re-dispatches; test-covered)*
- [x] Execution history, errors, status changes visible. *(monitor index + job detail: input/result/error/timing/attempts)*
- [x] Simple end-to-end automation demonstrable. *(POST webhook → queued job → worker → completed with result)*
- [ ] Walkthrough video recorded *(pending)*

**Implemented:** `flows` (trigger connector+entity → action module) + `execution_jobs`
(domain record, distinct from Laravel `jobs`) · `RunExecutionJob` queued worker
resolving modules via the registry · `FlowRunner` wiring webhook intake → matching
active flows → mapped input → queued jobs · Flow CRUD + activate/pause · Queue & Logs
monitor (status filter, stats, detail, manual retry) · queue-worker docs · seeded
active flow with completed + failed demo jobs · 6 feature tests (43 total, all green).

---

## M6 — Audit Trail, Security Hardening, QA & Final Handover  🟡

**Scope:** audit records for sensitive actions (login, user/connector/credential
changes, webhook processing, mapping changes, job retry, module status) · security
review (authn/z, credential masking, CSRF, validation, log/payload access) · fix
review issues · final docs + handover.

**Deliverables:** audit trail screen · security checklist + fixes summary · final
demo data · setup/handover docs · final walkthrough video.

**Acceptance:**
- [x] Full flow works: intake → mapping → job → queue → retry → logs → audit. *(exercised live + full-flow test)*
- [x] No milestone-blocking issues open. *(security review done; 2 findings fixed)*
- [x] Docs sufficient for another dev to run/continue. *(setup + architecture + schema + permissions + security + handover + walkthrough)*
- [x] Source, migrations, env notes, demo credentials/data delivered. *(seeded demo data + credentials table in docs/06)*
- [ ] Final walkthrough video recorded *(pending)*

**Implemented:** Audit Trail screen (filter by action, workspace-scoped, expandable
change sets) over the append-only `audit_logs` populated across M1–M5 · security
hardening pass — scoped `connector_id` validation to the workspace (cross-workspace
ref fix) + flows-index relationship fix · [security checklist](08-security-checklist.md)
with controls + findings + fixes · demo credentials + end-to-end walkthrough in the
handover doc · seeded audit entries · security/QA feature tests.

Docs: [08-security-checklist.md](08-security-checklist.md), [06-setup-and-handover.md](06-setup-and-handover.md)

---

## Cross-cutting delivery rules

- Modular & extensible: do not block future public SDK, marketplace, third-party
  modules, or visual automation.
- Any architecture change is explained **before** implementation.
- Every milestone: migrations + basic UI + demo data + docs + walkthrough.
