<?php

namespace App\Support;

use App\Models\Workspace;

/**
 * Holds the workspace the current request is operating in. Set by the
 * EnsureWorkspace middleware and read by the BelongsToWorkspace global scope.
 * Registered as a singleton in AppServiceProvider.
 */
class WorkspaceContext
{
    private ?Workspace $workspace = null;

    public function set(?Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }

    public function id(): ?int
    {
        return $this->workspace?->id;
    }

    public function check(): bool
    {
        return $this->workspace !== null;
    }
}
