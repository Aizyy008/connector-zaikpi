# CLAUDE.md

Project context for Claude Code. Read this first, then the relevant file under [docs/](docs/).

## What this project is

**Automation** is an iPaaS-style integration & automation platform (MVP). External
systems send webhooks; the platform ingests, maps, queues, and executes actions
against connected applications, with human approval gates, full audit, and
multi-workspace isolation.

The build is scoped into **6 milestones** — see [docs/04-milestones.md](docs/04-milestones.md).
Do not implement a later milestone's surface before its predecessor is accepted
unless explicitly asked.

## Stack (verified, not assumed)

| Concern | Choice |
|---|---|
| Framework | Laravel `^13.8`, PHP `^8.3` |
| Database | **MySQL** (`automation_app`) — dev default is root/no-password |
| Queue | `database` driver (Laravel `jobs`/`failed_jobs` tables) |
| Cache / Session | `file` (dev) |
| Auth | **Custom** session auth — NO Breeze/Jetstream/Fortify/Sanctum. See rules. |
| Authorization | Custom RBAC (roles + permissions tables), workspace-scoped |
| Credentials | Laravel `encrypted` casts (AES-256, APP_KEY), masked in UI |
| Frontend | **Tailwind CSS v4** + Blade + Vite (dual dark/light theme from client mockups) |
| Tests | PHPUnit `^12.5` |
| Lint | Laravel Pint |

## Golden rules

1. **Custom auth only.** Do not install or scaffold an auth starter kit. Build the
   guard, login controller, and session flow by hand. See [.claude/rules/security.md](.claude/rules/security.md).
2. **Everything is workspace-scoped.** Domain tables carry `workspace_id`. Queries
   must be constrained to the current workspace (global scope + middleware).
3. **Modules stay contract-driven.** New connectors/modules must not require editing
   the core. Follow the Module Contract in [docs/02-database-schema.md](docs/02-database-schema.md).
4. **Secrets are never rendered.** Credentials/tokens use encrypted casts and are
   masked (`••••1234`) in every view and log.
5. **Match the existing theme.** Build the UI with Tailwind v4 utilities + Blade
   components, driving the dual dark/light palette from the runtime CSS-variable
   tokens in [docs/05-ui-theme.md](docs/05-ui-theme.md). Don't add a second CSS framework.
6. **Deliverable = working code.** Each milestone ships migrations, UI, seed/demo
   data, docs, and a walkthrough — not screenshots alone.

## Where things live

- Product vision & glossary → [docs/00-overview.md](docs/00-overview.md)
- Architecture note (M1 deliverable) → [docs/01-architecture.md](docs/01-architecture.md)
- Full DB schema & Module Contract → [docs/02-database-schema.md](docs/02-database-schema.md)
- Roles/permissions model → [docs/03-permissions-model.md](docs/03-permissions-model.md)
- Milestone plan & acceptance → [docs/04-milestones.md](docs/04-milestones.md)
- Theme/UI integration → [docs/05-ui-theme.md](docs/05-ui-theme.md)
- Setup & handover → [docs/06-setup-and-handover.md](docs/06-setup-and-handover.md)
- Coding rules → [.claude/rules/](.claude/rules/)

## Common commands

```bash
composer run dev      # server + queue listener + pail logs + vite (concurrently)
php artisan serve     # app only
php artisan queue:work --tries=3   # process execution jobs
php artisan migrate --seed
php artisan test
./vendor/bin/pint     # format before committing
```

## Client mockups

The client supplied dual-theme HTML mockups (Dashboard, Connectors, Capabilities,
Roles & Policies, Routing, Mappings, Canonical, Flows/Automations, Queue & Logs,
Approval Review, Settings, API Settings, Webhooks). They define the target IA,
navigation, component styling, and light/dark palette. Treat them as the visual
source of truth — see [docs/05-ui-theme.md](docs/05-ui-theme.md).
