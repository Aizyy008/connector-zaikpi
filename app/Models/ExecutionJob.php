<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionJob extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'flow_id',
        'payload_id',
        'connector_id',
        'module_id',
        'type',
        'status',
        'input',
        'result',
        'error',
        'attempts',
        'queue_mode',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function payload(): BelongsTo
    {
        return $this->belongsTo(WebhookPayload::class, 'payload_id');
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'completed' => 'green',
            'processing', 'pending' => 'blue',
            'failed' => 'red',
            'held' => 'amber',
            default => 'gray',
        };
    }

    public function wasRetried(): bool
    {
        return $this->attempts > 1;
    }
}
