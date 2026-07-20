# 03 — Permissions Model

> Milestone 2 deliverable: documentation of the permission model.

## Model

Custom RBAC, **workspace-scoped**:

- A **user** belongs to one or more **workspaces** via `workspace_user`.
- Each membership row assigns exactly one **role** (the user's role *in that workspace*).
- A **role** has many **permissions** (`permission_role`).
- A user with `is_super_admin = true` bypasses workspace scoping and has all
  permissions everywhere (used for the seeded owner account).

```
User ──< workspace_user >── Workspace
             │ role_id
             ▼
           Role ──< permission_role >── Permission
```

## Enforcement (both layers — required by acceptance criteria)

1. **Backend** — the source of truth:
   - `EnsureWorkspace` middleware resolves the active workspace and rejects access to
     resources outside it.
   - `EnsurePermission:<slug>` middleware / Gate check on every write route.
   - Eloquent `BelongsToWorkspace` global scope prevents cross-workspace leakage in
     queries.
2. **UI** — convenience only, never the sole gate:
   - `@can` / helper checks hide buttons and nav the user cannot use.

> A restricted action must fail on a direct backend request even if the UI is
> bypassed. Test this explicitly.

## Seeded roles (from client mockups)

| Role | Slug | Intent |
|---|---|---|
| Super Admin | `super_admin` | Full access, governance, critical overrides |
| Ops Admin | `ops_admin` | Daily operations, connectors, queue/logs, approvals, settings (no critical override) |
| Reviewer | `reviewer` | Approve/reject exceptions; review-only audit access |
| Read-only Analyst | `analyst` | View dashboards/reports/logs; no changes, no triggers |

These give the ≥3 demonstrable access levels the milestone requires.

## Permission catalog (seed)

Grouped by module. Slugs are stable identifiers.

| Group | Permission slugs |
|---|---|
| workspaces | `workspaces.view`, `workspaces.manage` |
| users | `users.view`, `users.manage`, `roles.manage` |
| connectors | `connectors.view`, `connectors.write`, `connectors.test` |
| credentials | `credentials.view`, `credentials.manage` |
| modules | `modules.view`, `modules.manage` |
| capabilities | `capabilities.view`, `capabilities.manage` |
| routing | `routing.view`, `routing.manage` |
| mappings | `mappings.view`, `mappings.manage` |
| canonical | `canonical.view`, `canonical.manage` |
| webhooks | `webhooks.view`, `webhooks.manage` |
| payloads | `payloads.view` |
| flows | `flows.view`, `flows.manage`, `flows.execute` |
| queue | `queue.view`, `queue.retry` |
| approvals | `approvals.view`, `approvals.decide` |
| logs | `logs.view` |
| audit | `audit.view` |
| settings | `settings.view`, `settings.manage` |

## Default role → permission matrix (seed)

| Permission group | Super Admin | Ops Admin | Reviewer | Analyst |
|---|:--:|:--:|:--:|:--:|
| view (all groups) | ✅ | ✅ | ✅ | ✅ |
| connectors.write / .test | ✅ | ✅ | — | — |
| credentials.manage | ✅ | ✅ | — | — |
| modules.manage | ✅ | ✅ | — | — |
| capabilities.manage | ✅ | ✅ | — | — |
| routing.manage | ✅ | ⚠️ via approval | — | — |
| mappings.manage | ✅ | ✅ | — | — |
| canonical.manage | ✅ | — | — | — |
| webhooks.manage | ✅ | ✅ | — | — |
| flows.manage / .execute | ✅ | ✅ | — | — |
| queue.retry | ✅ | ✅ | — | — |
| approvals.decide | ✅ | ✅ | ✅ | — |
| audit.view | ✅ | ✅ | ✅ | — |
| users.manage / roles.manage | ✅ | — | — | — |
| workspaces.manage | ✅ | — | — | — |
| settings.manage | ✅ | ⚠️ partial | — | — |

⚠️ = allowed only through an approval rule (see Approval Rules in mockups), not a
direct write. Analysts get `*.view` + `logs.view` only.

## Approval rules (governance)

Certain actions require a decision even for privileged roles (mirrors the mockups'
Approval Rules table):

| Action | Required role | Condition |
|---|---|---|
| Flagged invoice creation | Reviewer | Source conflict / policy flag |
| Routing priority override | Super Admin | Temporary fallback change |
| Manual customer merge | Reviewer | Duplicate identity ambiguity |

## Seed / demo data (for testing access levels)

- 1 super admin (owner), 1 ops admin, 1 reviewer, 1 analyst.
- 2 workspaces (`core-operations`, `staging-sandbox`); place users so at least one
  belongs to only one workspace to prove isolation.
