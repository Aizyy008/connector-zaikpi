<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorCredential extends Model
{
    protected $fillable = [
        'connector_id',
        'key',
        'value',
        'type',
        'last_four',
        'expires_at',
        'rotated_at',
    ];

    // Never expose the decrypted secret via array/JSON serialization.
    protected $hidden = ['value'];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted', // AES-256 via APP_KEY
            'expires_at' => 'datetime',
            'rotated_at' => 'datetime',
        ];
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }

    /**
     * Store a secret: encrypts the value and records a non-sensitive hint for masking.
     */
    public function setSecret(string $plain): void
    {
        $this->value = $plain;
        $this->last_four = substr($plain, -4);
        $this->rotated_at = now();
    }

    /**
     * Masked representation for display — never reveals the secret.
     */
    public function masked(): string
    {
        return $this->last_four
            ? str_repeat('•', 8).$this->last_four
            : str_repeat('•', 12);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
