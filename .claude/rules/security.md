# Rule: Security & authentication

Non-negotiable. This is also the M6 security checklist.

## Custom authentication (client requirement)
- **Do not install or scaffold** Breeze, Jetstream, Fortify, Sanctum, or Socialite
  for the login flow. Build it by hand.
- Use the `web` session guard with the default `Illuminate\Auth` provider over the
  `users` table.
- Custom `LoginController`: validate credentials, `Auth::attempt()` with bcrypt,
  regenerate session on login, support "remember me", log out invalidates + regenerates.
- Rate-limit login (`RateLimiter` / `throttle` middleware) — lock after repeated failures.
- Passwords hashed with bcrypt (`BCRYPT_ROUNDS=12`). Never store or log plaintext.

## Authorization
- Enforce on the **backend** (middleware + Gate/policy) — UI `@can` is convenience only.
- Every write route is guarded by `EnsurePermission:<slug>` and workspace scope.
- Verify restricted actions fail on a direct request, not just a hidden button.

## Credentials & secrets
- API keys/tokens/secrets → `connector_credentials` with the `encrypted` cast (AES-256
  via `APP_KEY`). Webhook signing secrets too.
- Never render a secret in plaintext. Views show a masked value (`••••1234`); edit
  forms use "leave blank to keep unchanged".
- Add secret fields to `$hidden`; scrub them from logs and exception context.

## Web hardening
- CSRF token on every state-changing form/route (Laravel default — keep it on).
- Validate **all** input via Form Requests; reject unexpected fields.
- Webhook intake: verify HMAC signature + timestamp (replay window) before trusting
  a payload; store invalid payloads with a clear error status, don't 500.
- Mass-assignment: use `$fillable` deliberately; never `Model::unguard()`.
- Escape output in Blade (`{{ }}`, not `{!! !!}`) unless the value is trusted HTML.

## Audit (M6)
- Write an append-only `audit_logs` row for: login/logout, user changes, connector
  changes, credential changes/rotation, webhook processing, mapping changes, job
  retry, module status updates. Exclude secret values from `changes`.

## Files
- Never read or print `.env`, `storage/**`, or key/cert files (enforced in settings).
