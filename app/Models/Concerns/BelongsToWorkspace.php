<?php

namespace App\Models\Concerns;

use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to the current workspace (resolved from WorkspaceContext) and
 * auto-fills workspace_id on create. Prevents cross-workspace data leakage.
 *
 * Use ->withoutWorkspaceScope() for deliberate cross-workspace queries.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope('workspace', function (Builder $builder) {
            $id = app(WorkspaceContext::class)->id();

            if ($id !== null) {
                $model = $builder->getModel();
                $builder->where($model->getTable().'.workspace_id', $id);
            }
        });

        static::creating(function ($model) {
            if (empty($model->workspace_id) && ($id = app(WorkspaceContext::class)->id()) !== null) {
                $model->workspace_id = $id;
            }
        });
    }

    public function scopeWithoutWorkspaceScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('workspace');
    }

    /**
     * Constrain route-model binding to the active workspace. Binding runs before
     * the workspace middleware sets WorkspaceContext, so fall back to the session
     * value — this prevents cross-workspace access via a guessed id (404, not 200).
     */
    public function resolveRouteBinding($value, $field = null)
    {
        // Binding runs before the workspace middleware sets WorkspaceContext, so the
        // session holds the authoritative active workspace at this point.
        $workspaceId = session('current_workspace_id') ?? app(WorkspaceContext::class)->id();

        return $this->withoutWorkspaceScope()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->when($workspaceId !== null, fn (Builder $q) => $q->where($this->getTable().'.workspace_id', $workspaceId))
            ->first();
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
