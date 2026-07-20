# Documentation index

Project docs for **Automation** — an iPaaS-style integration & automation MVP built
on Laravel 13.

| Doc | Purpose |
|---|---|
| [00-overview.md](00-overview.md) | Product vision, primary user, glossary, module boundaries |
| [01-architecture.md](01-architecture.md) | Architecture note — DB, queue, credentials, auth, module extensibility (M1) |
| [02-database-schema.md](02-database-schema.md) | Full schema by milestone + Module Contract |
| [03-permissions-model.md](03-permissions-model.md) | Custom RBAC, roles, permission matrix, approval rules (M2) |
| [04-milestones.md](04-milestones.md) | Scope + acceptance tracking for all 6 milestones |
| [05-ui-theme.md](05-ui-theme.md) | Mockup→route map, design tokens, Blade porting plan |
| [06-setup-and-handover.md](06-setup-and-handover.md) | Setup, deployment, seed data, handover (M1/M6) |
| [07-git-workflow.md](07-git-workflow.md) | Branching model & per-milestone delivery/tagging |
| [08-security-checklist.md](08-security-checklist.md) | Security review, controls, fixes (M6) |


## Key decisions at a glance
- **MySQL** (`automation_app`), **database** queue driver, **file** session/cache (dev).
- **Custom** session authentication — no auth starter kit.
- Custom, **workspace-scoped** RBAC.
- Credentials encrypted (AES-256 via `APP_KEY`), masked in UI.
- Contract-driven modules/connectors — core never edited to add one.
- Dual-theme (dark/light) UI built with **Tailwind CSS v4 + Blade** from client mockups.
