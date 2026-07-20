# Rule: Laravel conventions

Applies to all PHP work in this repo. Laravel 13 / PHP 8.3.

## Structure
- Controllers are **thin**. Business logic lives in `app/Services/*`; validation in
  Form Requests (`app/Http/Requests/*`); authorization in policies/Gates + middleware.
- Admin controllers under `App\Http\Controllers\Admin`; webhook intake under
  `App\Http\Controllers\Webhooks`.
- Queued work is a `ShouldQueue` job in `app/Jobs/*`. A domain `execution_jobs` row
  tracks status/result — do not rely on Laravel's internal `jobs` table for UI state.

## Models & migrations
- One migration per table; use FK constraints and index every `workspace_id` and
  status column. Prefer `enum`/string status columns as documented in the schema.
- Every domain model uses the `BelongsToWorkspace` trait (global scope on
  `workspace_id`). Never write a domain query that can cross workspaces.
- Use `casts()` for `json`, `encrypted`, `datetime`, `boolean`. Secrets use the
  `encrypted` cast and are listed in `$hidden`.
- Use factories + seeders for all demo data. Seeders must be idempotent
  (`updateOrCreate`).

## PHP style
- Typed properties, return types, and constructor property promotion.
- Enums (PHP `enum`) for fixed sets (statuses, roles) where practical.
- Run `./vendor/bin/pint` before finishing. Follow PSR-12.

## Don't
- Don't install an auth starter kit (see security.md).
- Don't add a new heavy dependency without noting why in the milestone doc first.
- Don't reference modules/connectors by concrete class in routing/flows — use `slug`.
- Don't put secrets in logs, exceptions, or blade output.
