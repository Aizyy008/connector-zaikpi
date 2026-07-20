<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flow extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'name',
        'slug',
        'version',
        'definition',
        'status',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
        ];
    }

    public function executionJobs(): HasMany
    {
        return $this->hasMany(ExecutionJob::class);
    }

    public function triggerConnectorId(): ?int
    {
        return $this->definition['trigger']['connector_id'] ?? null;
    }

    public function triggerEntity(): ?string
    {
        return $this->definition['trigger']['entity'] ?? null;
    }

    public function actionModule(): ?string
    {
        return $this->definition['action']['module'] ?? null;
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'active' => 'green',
            'paused' => 'amber',
            default => 'gray',
        };
    }
}
