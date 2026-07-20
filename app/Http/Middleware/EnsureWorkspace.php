<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the workspace the request operates in and enforces that the user may
 * access it. Sets WorkspaceContext (used by the BelongsToWorkspace scope) and
 * shares view state. Runs after 'auth'.
 */
class EnsureWorkspace
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Workspaces the user may operate in (super admins: all).
        $available = $user->is_super_admin
            ? Workspace::where('status', 'active')->orderBy('name')->get()
            : $user->workspaces()->where('status', 'active')->orderBy('name')->get();

        if ($available->isEmpty()) {
            abort(403, 'You are not assigned to any active workspace.');
        }

        // Selected workspace comes from session; validate it is still allowed.
        $selectedId = $request->session()->get('current_workspace_id');
        $current = $available->firstWhere('id', $selectedId) ?? $available->first();

        $request->session()->put('current_workspace_id', $current->id);
        $this->context->set($current);

        // Share with all views (sidebar switcher, headers).
        View::share('currentWorkspace', $current);
        View::share('availableWorkspaces', $available);

        return $next($request);
    }
}
