<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookEndpoint extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'connector_id',
        'name',
        'slug',
        'secret',
        'signature_algo',
        'signature_header',
        'entity',
        'enabled',
    ];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted', // signing key never stored/rendered in plaintext
            'enabled' => 'boolean',
        ];
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }

    public function payloads(): HasMany
    {
        return $this->hasMany(WebhookPayload::class, 'endpoint_id');
    }

    public function hasSecret(): bool
    {
        return filled($this->secret);
    }

    public function publicPath(): string
    {
        return "/webhooks/{$this->slug}";
    }
}
