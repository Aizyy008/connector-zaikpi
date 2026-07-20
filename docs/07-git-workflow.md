# 07 — Git Workflow & Milestone Delivery

How the repository is branched so each milestone can be delivered and reviewed by
the client independently.

## Branch model

- **`main`** — delivered, client-accepted work only. Always runnable. Never commit
  work-in-progress here.
- **`milestone-one` … `milestone-six`** — one branch per milestone. All work for a
  milestone happens on its branch.
- Each milestone branches **from the previous accepted milestone** (i.e. from `main`
  after the prior milestone is merged), so scope builds up cleanly.

```
main ──●───────────────●───────────────●── ...
        \             / \             /
         milestone-one   milestone-two   ...
         (M1 work)        (M2 work)
```

## Lifecycle of a milestone

1. Create the branch from up-to-date `main`:
   ```bash
   git checkout main && git pull        # once a remote exists
   git checkout -b milestone-two        # e.g. starting M2
   ```
2. Do the work in focused commits (migrations, UI, seeders, docs, tests).
3. Open a PR `milestone-N → main` for client review (or hand off the branch).
4. On acceptance, merge to `main` and **tag** the delivery:
   ```bash
   git checkout main && git merge --no-ff milestone-two
   git tag -a v0.2.0-m2 -m "Milestone 2 — Workspaces, Users, Roles & Permissions"
   ```
5. Start the next milestone branch from the new `main`.

## Tag convention

| Milestone | Tag |
|---|---|
| M1 Foundation | `v0.1.0-m1` |
| M2 Workspaces/RBAC | `v0.2.0-m2` |
| M3 Connectors/Modules/Credentials | `v0.3.0-m3` |
| M4 Webhooks/Payloads/Mapping | `v0.4.0-m4` |
| M5 Execution/Queues/Flows | `v0.5.0-m5` |
| M6 Audit/Hardening/Handover | `v1.0.0` (MVP) |

## Commit conventions

- Present-tense, scoped messages: `M1: add custom login controller + session guard`.
- Keep migrations, their models, and seeders in coherent commits.
- Never commit `.env`, secrets, `storage/*`, or vendor build artifacts (see `.gitignore`).
- Run `./vendor/bin/pint` before committing.

## Delivery checklist per milestone (see [04-milestones.md](04-milestones.md))
- [ ] Functional code (migrations + UI + demo data)
- [ ] Docs updated
- [ ] Walkthrough video
- [ ] Acceptance criteria met → merge to `main` + tag
