<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class AuditLog extends Model
{
    // Append-only: managed created_at only, never updated.
    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'changes',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Field names whose values must never be written to the audit trail
     * (password hashes, tokens, secrets, encrypted credential blobs). Matched
     * case-insensitively, and as a substring so `client_secret`, `api_token`,
     * `webhook_secret`, etc. are all caught.
     */
    private const SENSITIVE_KEYS = [
        'password', 'remember_token', 'secret', 'token', 'api_key', 'apikey',
        'private_key', 'client_secret', 'signature', 'authorization', 'credential',
    ];

    /**
     * Record an audit entry. Sensitive values are always scrubbed defensively —
     * callers should still avoid passing secrets, but this guarantees password
     * hashes/tokens/secrets can never land in `audit_logs` even via getChanges().
     */
    public static function record(string $action, ?Model $auditable = null, array $changes = [], ?int $workspaceId = null): self
    {
        $request = request();

        return static::create([
            'workspace_id' => $workspaceId,
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'changes' => $changes ? static::scrub($changes) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * Redact any sensitive keys (recursively) before persisting. The key is kept
     * so the trail still records *that* a field changed, but the value is masked.
     * Also catches raw secret-shaped VALUES (e.g. a bcrypt hash) regardless of key,
     * which is what the `audit:sanitize` command relies on to clean legacy rows.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function scrub(array $changes): array
    {
        foreach ($changes as $key => $value) {
            if (is_string($key) && static::isSensitive($key)) {
                $changes[$key] = '••••[redacted]';
            } elseif (is_array($value)) {
                $changes[$key] = static::scrub($value);
            } elseif (is_string($value) && static::looksLikeSecretValue($value)) {
                $changes[$key] = '••••[redacted]';
            }
        }

        return $changes;
    }

    /**
     * Heuristic for raw secret values that must never sit in the audit trail —
     * bcrypt/argon password hashes and Laravel encrypted-cast blobs.
     */
    protected static function looksLikeSecretValue(string $value): bool
    {
        // bcrypt ($2y$/$2a$/$2b$) or argon2 ($argon2...) password hashes.
        if (preg_match('/^\$(2[aby]|argon2)/', $value)) {
            return true;
        }

        // Laravel encrypted-cast payload (base64 JSON with iv/value/mac).
        $decoded = base64_decode($value, true);

        return $decoded !== false && str_contains($decoded, '"iv"') && str_contains($decoded, '"mac"');
    }

    protected static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
