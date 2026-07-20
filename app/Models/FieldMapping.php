<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldMapping extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'connector_id',
        'entity',
        'source_field',
        'target_field',
        'transform',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'transform' => 'array',
        ];
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'validated' => 'green',
            'warning' => 'red',
            default => 'amber',
        };
    }
}
