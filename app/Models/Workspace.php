<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'environment',
        'status',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * Members of this workspace. Pivot carries their role_id.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot('role_id')
            ->withTimestamps();
    }

    public function connectors(): HasMany
    {
        return $this->hasMany(Connector::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(Module::class);
    }
}
