<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\ConnectorController;
use App\Http\Controllers\Admin\ConnectorCredentialController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ExecutionJobController;
use App\Http\Controllers\Admin\FieldMappingController;
use App\Http\Controllers\Admin\FlowController;
use App\Http\Controllers\Admin\ModuleController;
use App\Http\Controllers\Admin\PayloadController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WebhookEndpointController;
use App\Http\Controllers\Admin\WorkspaceController;
use App\Http\Controllers\Admin\WorkspaceSwitchController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Webhooks\WebhookIntakeController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('admin.dashboard'));

/*
 * Public webhook intake — unauthenticated, CSRF-exempt (see bootstrap/app.php).
 * Resolves the workspace/connector from the endpoint slug.
 */
Route::post('webhooks/{slug}', [WebhookIntakeController::class, 'store'])->name('webhooks.intake');

/*
 * Guest (unauthenticated) routes — custom authentication.
 */
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'login'])->name('login.attempt');
});

Route::post('logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

/*
 * Protected admin panel. `auth` guards the session; `workspace` resolves and
 * enforces the active workspace; per-route `can:` gates enforce permissions on
 * the backend (mirrored by @can in the UI).
 */
Route::middleware(['auth', 'workspace'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // Switch active workspace (membership enforced in the controller).
        Route::post('workspace/switch', [WorkspaceSwitchController::class, 'update'])->name('workspace.switch');

        // Workspaces — view for members; manage for super admin.
        Route::get('workspaces', [WorkspaceController::class, 'index'])
            ->middleware('can:workspaces.view')->name('workspaces.index');
        Route::middleware('can:workspaces.manage')->group(function () {
            Route::get('workspaces/create', [WorkspaceController::class, 'create'])->name('workspaces.create');
            Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
            Route::get('workspaces/{workspace}/edit', [WorkspaceController::class, 'edit'])->name('workspaces.edit');
            Route::put('workspaces/{workspace}', [WorkspaceController::class, 'update'])->name('workspaces.update');
            Route::delete('workspaces/{workspace}', [WorkspaceController::class, 'destroy'])->name('workspaces.destroy');
        });

        // Users — view for the workspace; manage for privileged roles.
        Route::get('users', [UserController::class, 'index'])
            ->middleware('can:users.view')->name('users.index');
        Route::middleware('can:users.manage')->group(function () {
            Route::get('users/create', [UserController::class, 'create'])->name('users.create');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        });

        /*
         * Connectors — workspace-scoped registry. View for members; write/test gated.
         */
        Route::get('connectors', [ConnectorController::class, 'index'])
            ->middleware('can:connectors.view')->name('connectors.index');
        Route::middleware('can:connectors.write')->group(function () {
            Route::get('connectors/create', [ConnectorController::class, 'create'])->name('connectors.create');
            Route::post('connectors', [ConnectorController::class, 'store'])->name('connectors.store');
        });
        Route::get('connectors/{connector}', [ConnectorController::class, 'show'])
            ->middleware('can:connectors.view')->name('connectors.show');
        Route::post('connectors/{connector}/test', [ConnectorController::class, 'test'])
            ->middleware('can:connectors.test')->name('connectors.test');
        Route::middleware('can:connectors.write')->group(function () {
            Route::get('connectors/{connector}/edit', [ConnectorController::class, 'edit'])->name('connectors.edit');
            Route::put('connectors/{connector}', [ConnectorController::class, 'update'])->name('connectors.update');
            Route::delete('connectors/{connector}', [ConnectorController::class, 'destroy'])->name('connectors.destroy');
        });

        // Connector credentials (encrypted; never rendered).
        Route::middleware('can:credentials.manage')->group(function () {
            Route::post('connectors/{connector}/credentials', [ConnectorCredentialController::class, 'store'])->name('connectors.credentials.store');
            Route::put('connectors/{connector}/credentials/{credential}', [ConnectorCredentialController::class, 'update'])->name('connectors.credentials.update');
            Route::delete('connectors/{connector}/credentials/{credential}', [ConnectorCredentialController::class, 'destroy'])->name('connectors.credentials.destroy');
        });

        /*
         * Modules — contract-driven global registry.
         */
        Route::get('modules', [ModuleController::class, 'index'])
            ->middleware('can:modules.view')->name('modules.index');
        Route::middleware('can:modules.manage')->group(function () {
            Route::post('modules/sync', [ModuleController::class, 'sync'])->name('modules.sync');
            Route::get('modules/create', [ModuleController::class, 'create'])->name('modules.create');
            Route::post('modules', [ModuleController::class, 'store'])->name('modules.store');
            Route::get('modules/{module}/edit', [ModuleController::class, 'edit'])->name('modules.edit');
            Route::put('modules/{module}', [ModuleController::class, 'update'])->name('modules.update');
            Route::delete('modules/{module}', [ModuleController::class, 'destroy'])->name('modules.destroy');
            Route::patch('modules/{module}/toggle', [ModuleController::class, 'toggle'])->name('modules.toggle');
            Route::post('modules/{module}/health', [ModuleController::class, 'health'])->name('modules.health');
        });
        Route::get('modules/{module}', [ModuleController::class, 'show'])
            ->middleware('can:modules.view')->name('modules.show');

        /*
         * Webhook endpoints (workspace-scoped). View for members; manage gated.
         */
        Route::get('webhooks', [WebhookEndpointController::class, 'index'])
            ->middleware('can:webhooks.view')->name('webhooks.index');
        Route::middleware('can:webhooks.manage')->group(function () {
            Route::get('webhooks/create', [WebhookEndpointController::class, 'create'])->name('webhooks.create');
            Route::post('webhooks', [WebhookEndpointController::class, 'store'])->name('webhooks.store');
            Route::get('webhooks/{webhook}/edit', [WebhookEndpointController::class, 'edit'])->name('webhooks.edit');
            Route::put('webhooks/{webhook}', [WebhookEndpointController::class, 'update'])->name('webhooks.update');
            Route::post('webhooks/{webhook}/regenerate', [WebhookEndpointController::class, 'regenerate'])->name('webhooks.regenerate');
            Route::delete('webhooks/{webhook}', [WebhookEndpointController::class, 'destroy'])->name('webhooks.destroy');
        });

        // Payload logs (raw + parsed inspection).
        Route::get('payloads', [PayloadController::class, 'index'])
            ->middleware('can:payloads.view')->name('payloads.index');
        Route::get('payloads/{payload}', [PayloadController::class, 'show'])
            ->middleware('can:payloads.view')->name('payloads.show');

        // Field mappings.
        Route::get('mappings', [FieldMappingController::class, 'index'])
            ->middleware('can:mappings.view')->name('mappings.index');
        Route::middleware('can:mappings.manage')->group(function () {
            Route::get('mappings/create', [FieldMappingController::class, 'create'])->name('mappings.create');
            Route::post('mappings', [FieldMappingController::class, 'store'])->name('mappings.store');
            Route::get('mappings/{mapping}/edit', [FieldMappingController::class, 'edit'])->name('mappings.edit');
            Route::put('mappings/{mapping}', [FieldMappingController::class, 'update'])->name('mappings.update');
            Route::delete('mappings/{mapping}', [FieldMappingController::class, 'destroy'])->name('mappings.destroy');
        });

        /*
         * Flows / Automations — trigger + action definitions.
         */
        Route::get('flows', [FlowController::class, 'index'])
            ->middleware('can:flows.view')->name('flows.index');
        Route::middleware('can:flows.manage')->group(function () {
            Route::get('flows/create', [FlowController::class, 'create'])->name('flows.create');
            Route::post('flows', [FlowController::class, 'store'])->name('flows.store');
            Route::get('flows/{flow}/edit', [FlowController::class, 'edit'])->name('flows.edit');
            Route::put('flows/{flow}', [FlowController::class, 'update'])->name('flows.update');
            Route::patch('flows/{flow}/toggle', [FlowController::class, 'toggle'])->name('flows.toggle');
            Route::delete('flows/{flow}', [FlowController::class, 'destroy'])->name('flows.destroy');
        });

        /*
         * Queue & Logs — execution job monitoring + manual retry.
         */
        Route::get('queue', [ExecutionJobController::class, 'index'])
            ->middleware('can:queue.view')->name('queue.index');
        Route::get('queue/{job}', [ExecutionJobController::class, 'show'])
            ->middleware('can:queue.view')->name('queue.show');
        Route::post('queue/{job}/retry', [ExecutionJobController::class, 'retry'])
            ->middleware('can:queue.retry')->name('queue.retry');

        // Audit trail — append-only record of sensitive actions.
        Route::get('audit', [AuditController::class, 'index'])
            ->middleware('can:audit.view')->name('audit.index');

        // Roles & permissions — matrix visible to viewers; editing needs roles.manage.
        Route::get('roles', [RoleController::class, 'index'])
            ->middleware('can:users.view')->name('roles.index');
        Route::middleware('can:roles.manage')->group(function () {
            Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
            Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
            Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
            Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        });
    });
