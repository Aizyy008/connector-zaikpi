<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Connector extends Model
{
    use BelongsToWorkspace;

    public const STATUSES = ['healthy', 'warning', 'disconnected'];

    protected $fillable = [
        'workspace_id',
        'name',
        'slug',
        'type',
        'provider',
        'role',
        'status',
        'enabled',
        'config',
        'last_health_status',
        'health_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'config' => 'array',
            'health_checked_at' => 'datetime',
        ];
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(ConnectorCredential::class);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'healthy' => 'green',
            'warning' => 'amber',
            default => 'red',
        };
    }
}
