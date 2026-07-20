<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookPayload extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'connector_id',
        'endpoint_id',
        'headers',
        'raw_payload',
        'parsed_payload',
        'status',
        'error',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'parsed_payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id');
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'valid', 'processed' => 'green',
            'received' => 'blue',
            'invalid', 'failed' => 'red',
            default => 'gray',
        };
    }
}
