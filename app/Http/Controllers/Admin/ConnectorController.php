<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Connector;
use App\Services\ConnectorTester;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ConnectorController extends Controller
{
    public const TYPES = ['ecommerce', 'business_system', 'marketing', 'platform', 'social', 'other'];

    public const ROLES = ['primary_source', 'secondary_source', 'action_system', 'outbound', 'none'];

    public function __construct(private readonly WorkspaceContext $context) {}

    public function index(): View
    {
        // Scoped to the current workspace by the BelongsToWorkspace global scope.
        $connectors = Connector::withCount('credentials')->orderBy('name')->get();

        $stats = [
            'total' => $connectors->count(),
            'healthy' => $connectors->where('status', 'healthy')->count(),
            'warning' => $connectors->where('status', 'warning')->count(),
            'disconnected' => $connectors->where('status', 'disconnected')->count(),
        ];

        return view('admin.connectors.index', compact('connectors', 'stats'));
    }

    public function create(): View
    {
        return view('admin.connectors.form', ['connector' => new Connector(['role' => 'none', 'enabled' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $connector = Connector::create($data); // workspace_id auto-filled by scope
        AuditLog::record('connector.created', $connector, ['name' => $connector->name], $this->context->id());

        return redirect()->route('admin.connectors.show', $connector)->with('status', "Connector “{$connector->name}” created.");
    }

    public function show(Connector $connector): View
    {
        $connector->load('credentials');

        return view('admin.connectors.show', compact('connector'));
    }

    public function edit(Connector $connector): View
    {
        return view('admin.connectors.form', compact('connector'));
    }

    public function update(Request $request, Connector $connector): RedirectResponse
    {
        $connector->update($this->validated($request, $connector));
        AuditLog::record('connector.updated', $connector, $connector->getChanges(), $this->context->id());

        return redirect()->route('admin.connectors.show', $connector)->with('status', 'Connector updated.');
    }

    public function destroy(Connector $connector): RedirectResponse
    {
        AuditLog::record('connector.deleted', $connector, ['name' => $connector->name], $this->context->id());
        $connector->delete();

        return redirect()->route('admin.connectors.index')->with('status', 'Connector deleted.');
    }

    public function test(Connector $connector, ConnectorTester $tester): RedirectResponse
    {
        $result = $tester->test($connector);
        AuditLog::record('connector.tested', $connector, ['status' => $result['status']], $this->context->id());

        return back()->with('status', "Health check: {$result['status']} — {$result['message']}");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Connector $connector = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(self::TYPES)],
            'provider' => ['nullable', 'string', 'max:120'],
            'role' => ['required', Rule::in(self::ROLES)],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $data['enabled'] = $request->boolean('enabled');
        $data['slug'] = $connector?->slug ?? Str::slug($data['name']).'-'.Str::lower(Str::random(4));

        return $data;
    }
}
