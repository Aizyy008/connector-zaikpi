<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One workspace context per request.
        $this->app->scoped(WorkspaceContext::class);
    }

    public function boot(): void
    {
        // Super admins bypass every gate.
        Gate::before(fn (User $user) => $user->is_super_admin ? true : null);

        // Register a gate per permission slug (from the static catalog, so this
        // never depends on the DB at boot). Each gate resolves the user's role in
        // the request's current workspace at authorization time.
        foreach (Permissions::slugs() as $slug) {
            Gate::define($slug, fn (User $user) => $user->hasPermissionTo($slug));
        }
    }
}
