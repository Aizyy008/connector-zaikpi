# 08 — Security Checklist & Review (Milestone 6)

Review of the MVP's security-sensitive surfaces, the controls in place, and the
fixes applied during the M6 hardening pass

## Checklist

| Area | Control | Status |
|---|---|---|
| Authentication | Custom session guard; `Auth::attempt` with bcrypt (`BCRYPT_ROUNDS=12`); session regenerated on login; invalidated + token regenerated on logout | ✅ |
| Login abuse | Rate limiter — 5 attempts per email+IP, lockout with retry-after | ✅ |
| Account state | Non-`active` users blocked at login even with valid credentials | ✅ |
| Authorization (backend) | Per-permission Gates + `can:` route middleware on every write route; `Gate::before` super-admin bypass | ✅ |
| Authorization (UI) | `@can` hides controls — convenience only, never the sole gate | ✅ |
| Tenant isolation | `BelongsToWorkspace` global scope + `resolveRouteBinding` (bound models 404 across workspaces); membership enforced on workspace switch | ✅ |
| Cross-workspace refs | `connector_id` inputs validated with a workspace-scoped `exists` rule (fix below) | ✅ (fixed) |
| Credential storage | `encrypted` cast (AES-256 / `APP_KEY`); `$hidden`; masked display; leave-blank-to-keep edit | ✅ |
| Webhook secrets | Same encrypted + hidden treatment; shown once on rotate | ✅ |
| Webhook authenticity | HMAC-SHA256 signature verified with `hash_equals`; bad/invalid payloads logged, not trusted | ✅ |
| CSRF | Enabled on all web forms; only the public `webhooks/*` intake is exempt (external callers, verified by signature instead) | ✅ |
| Input validation | Every write validated via controller rules; explicit field lists (no `$request->all()` mass-assign) | ✅ |
| Mass assignment | Models use `$fillable`; controllers build attribute arrays explicitly; `workspace_id` set by the scope, not from request | ✅ |
| Output escaping | Blade `{{ }}` throughout; no `{!! !!}` on user data | ✅ |
| Secrets in logs | Credentials/secrets excluded from audit `changes`; sensitive headers stripped from stored webhook headers | ✅ |
| Log/payload access | Payload logs, queue, and audit gated by `payloads.view` / `queue.view` / `audit.view` and workspace-scoped | ✅ |
| Audit trail | Append-only `audit_logs` (no update path); records login, user/role/workspace/connector/credential/webhook/mapping/flow/module/execution changes | ✅ |

## Fixes applied in the M6 pass

1. **Cross-workspace connector reference (medium).** `connector_id` on field
   mappings, webhook endpoints, and flows used an unscoped `exists:connectors,id`
   rule, so a crafted form post could attach a connector belonging to another
   workspace. Fixed by scoping the rule to the active workspace:
   `Rule::exists('connectors','id')->where('workspace_id', $context->id())`.

2. **Flows index crash (correctness).** `Flow::with('connector')` referenced a
   non-existent relationship (trigger connector lives in the definition JSON) and
   threw on `/admin/flows`. Resolved via a connector-name lookup + regression test.

## Residual notes / future work (non-blocking)

- Webhook intake has no per-endpoint rate limit — add throttling before exposing
  publicly at scale.
- 2FA and IP allowlisting are surfaced in Settings mockups but out of MVP scope.
- Consider a signed-URL or per-tenant path prefix for webhook endpoints if slug
  guessing becomes a concern (payloads are already signature-gated).
- Set `APP_DEBUG=false` and rotate `APP_KEY` handling per the setup doc in prod.

## How this was verified

- Feature tests assert backend permission denials (403) independent of the UI,
  workspace isolation (404 across workspaces), credential/secret encryption at rest,
  webhook signature rejection, and the cross-workspace connector rule.
- Full end-to-end flow exercised live: signed webhook → payload log → mapping →
  queued execution job → worker completion → retry → audit entries.
