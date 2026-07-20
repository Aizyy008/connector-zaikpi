<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Module;
use App\Modules\ModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ModuleController extends Controller
{
    private const TYPES = ['trigger', 'action', 'transform'];

    private const EXECUTION_METHODS = ['sync', 'queue', 'webhook'];

    public function __construct(private readonly ModuleRegistry $registry) {}

    public function index(): View
    {
        $modules = Module::orderBy('type')->orderBy('name')->get();

        return view('admin.modules.index', compact('modules'));
    }

    public function create(): View
    {
        return view('admin.modules.form', [
            'module' => new Module(['type' => 'action', 'execution_method' => 'queue', 'enabled' => true]),
            'types' => self::TYPES,
            'executionMethods' => self::EXECUTION_METHODS,
            'codeBacked' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $module = Module::create($this->attributes($data, null));
        AuditLog::record('module.created', $module, ['slug' => $module->slug, 'name' => $module->name]);

        return redirect()->route('admin.modules.show', $module)
            ->with('status', "Module “{$module->name}” created.");
    }

    public function show(Module $module): View
    {
        return view('admin.modules.show', compact('module'));
    }

    public function edit(Module $module): View
    {
        return view('admin.modules.form', [
            'module' => $module,
            'types' => self::TYPES,
            'executionMethods' => self::EXECUTION_METHODS,
            // A code-backed module's slug is the contract key — locking it prevents
            // silently detaching flows/jobs that reference it.
            'codeBacked' => $this->registry->find($module->slug) !== null,
        ]);
    }

    public function update(Request $request, Module $module): RedirectResponse
    {
        $codeBacked = $this->registry->find($module->slug) !== null;
        $data = $this->validated($request, $module, $codeBacked);

        $module->update($this->attributes($data, $module, $codeBacked));
        AuditLog::record('module.updated', $module, $module->getChanges(), $module->workspace_id);

        return redirect()->route('admin.modules.show', $module)
            ->with('status', "Module “{$module->name}” updated.");
    }

    public function toggle(Module $module): RedirectResponse
    {
        $module->update(['enabled' => ! $module->enabled]);
        AuditLog::record('module.status_updated', $module, ['enabled' => $module->enabled]);

        return back()->with('status', "Module “{$module->name}” ".($module->enabled ? 'enabled' : 'disabled').'.');
    }

    public function health(Module $module): RedirectResponse
    {
        $contract = $this->registry->find($module->slug);

        if (! $contract) {
            $module->update(['health_status' => 'unavailable']);

            return back()->with('error', 'No code contract is registered for this module — marked unavailable.');
        }

        $module->update(['health_status' => $contract->healthCheck()->value]);

        return back()->with('status', "Health check: {$module->health_status}.");
    }

    public function sync(): RedirectResponse
    {
        $result = $this->registry->sync();
        AuditLog::record('module.synced', null, $result);

        return back()->with('status', "Synced {$result['synced']} module(s) from code.");
    }

    public function destroy(Module $module): RedirectResponse
    {
        // Code-backed modules would reappear on the next sync — block deletion to
        // avoid confusion; they can be disabled instead.
        if ($this->registry->find($module->slug)) {
            return back()->with('error', 'This module is defined in code and cannot be deleted. Disable it instead.');
        }

        AuditLog::record('module.deleted', $module, ['slug' => $module->slug], $module->workspace_id);
        $module->delete();

        return redirect()->route('admin.modules.index')->with('status', 'Module deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Module $module = null, bool $codeBacked = false): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(self::TYPES)],
            'description' => ['nullable', 'string', 'max:1000'],
            'execution_method' => ['required', Rule::in(self::EXECUTION_METHODS)],
            'version' => ['nullable', 'string', 'max:20'],
            'scopes' => ['nullable', 'string', 'max:500'],
            'input_schema' => ['nullable', 'string', 'json'],
            'output_schema' => ['nullable', 'string', 'json'],
            'enabled' => ['sometimes', 'boolean'],
        ];

        // Slug is immutable for code-backed modules; otherwise required + unique.
        if (! $codeBacked) {
            $rules['slug'] = ['required', 'string', 'max:120', 'regex:/^[a-z0-9._-]+$/', Rule::unique('modules', 'slug')->ignore($module?->id)];
        }

        return $request->validate($rules);
    }

    /**
     * Build the persistable attributes from validated input.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?Module $module, bool $codeBacked = false): array
    {
        $attributes = [
            'name' => $data['name'],
            'type' => $data['type'],
            'description' => $data['description'] ?? null,
            'execution_method' => $data['execution_method'],
            'version' => $data['version'] ?? '1.0.0',
            'scopes' => $this->toList($data['scopes'] ?? null),
            'input_schema' => $this->toJson($data['input_schema'] ?? null),
            'output_schema' => $this->toJson($data['output_schema'] ?? null),
            'enabled' => (bool) ($data['enabled'] ?? false),
        ];

        if (! $codeBacked) {
            $attributes['slug'] = $data['slug'] ?? $module?->slug;
            // A manually-defined module has no executable code contract yet, so it
            // cannot run — reflect that honestly in its health.
            $attributes['health_status'] = 'unavailable';
        }

        return $attributes;
    }

    /**
     * @return array<int, string>|null
     */
    private function toList(?string $value): ?array
    {
        if (! $value) {
            return null;
        }

        return collect(explode(',', $value))->map(fn ($v) => trim($v))->filter()->values()->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function toJson(?string $value): ?array
    {
        if (! $value) {
            return null;
        }

        return json_decode($value, true);
    }
}
