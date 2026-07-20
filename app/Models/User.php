<?php

namespace App\Models;

use App\Support\WorkspaceContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'is_super_admin',
        'status',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * Workspaces this user belongs to. Pivot carries the user's role in each.
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->withPivot('role_id')
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * The user's role within a given workspace (null if not a member).
     */
    public function roleIn(?Workspace $workspace): ?Role
    {
        if (! $workspace) {
            return null;
        }

        $membership = $this->workspaces()
            ->where('workspaces.id', $workspace->id)
            ->first();

        return $membership ? Role::find($membership->pivot->role_id) : null;
    }

    public function belongsToWorkspace(Workspace $workspace): bool
    {
        return $this->is_super_admin
            || $this->workspaces()->where('workspaces.id', $workspace->id)->exists();
    }

    /**
     * Permission check evaluated in a workspace context. Super admins bypass.
     * Falls back to the request's current workspace when none is passed.
     */
    public function hasPermissionTo(string $slug, ?Workspace $workspace = null): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        $workspace ??= app(WorkspaceContext::class)->get();

        return $this->roleIn($workspace)?->hasPermission($slug) ?? false;
    }
}
