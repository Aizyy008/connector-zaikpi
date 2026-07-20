<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Connector;
use App\Models\Flow;
use App\Models\Module;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FlowController extends Controller
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function index(): View
    {
        $flows = Flow::withCount('executionJobs')->orderBy('name')->get();

        // Trigger connector lives in the definition JSON (not a FK) — resolve names.
        $connectorNames = Connector::pluck('name', 'id');

        return view('admin.flows.index', compact('flows', 'connectorNames'));
    }

    public function create(): View
    {
        return view('admin.flows.form', [
            'flow' => new Flow(['status' => 'draft']),
        ] + $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $flow = Flow::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
            'status' => $data['status'],
            'definition' => $this->definition($data),
        ]);

        AuditLog::record('flow.created', $flow, ['name' => $flow->name], $this->context->id());

        return redirect()->route('admin.flows.index')->with('status', "Flow “{$flow->name}” created.");
    }

    public function edit(Flow $flow): View
    {
        return view('admin.flows.form', ['flow' => $flow] + $this->formData());
    }

    public function update(Request $request, Flow $flow): RedirectResponse
    {
        $data = $this->validated($request);

        $flow->update([
            'name' => $data['name'],
            'status' => $data['status'],
            'definition' => $this->definition($data),
        ]);

        AuditLog::record('flow.updated', $flow, $flow->getChanges(), $this->context->id());

        return redirect()->route('admin.flows.index')->with('status', "Flow “{$flow->name}” updated.");
    }

    public function toggle(Flow $flow): RedirectResponse
    {
        $flow->update(['status' => $flow->status === 'active' ? 'paused' : 'active']);
        AuditLog::record('flow.status_updated', $flow, ['status' => $flow->status], $this->context->id());

        return back()->with('status', "Flow “{$flow->name}” is now {$flow->status}.");
    }

    public function destroy(Flow $flow): RedirectResponse
    {
        AuditLog::record('flow.deleted', $flow, ['name' => $flow->name], $this->context->id());
        $flow->delete();

        return redirect()->route('admin.flows.index')->with('status', 'Flow deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'connectors' => Connector::orderBy('name')->get(),
            'actionModules' => Module::where('type', 'action')->where('enabled', true)->orderBy('name')->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'connector_id' => ['required', 'integer', Rule::exists('connectors', 'id')->where('workspace_id', $this->context->id())],
            'entity' => ['required', 'string', 'max:80'],
            'module' => ['required', 'string', Rule::exists('modules', 'slug')->where('enabled', true)],
            'status' => ['required', Rule::in(['draft', 'active', 'paused'])],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(array $data): array
    {
        return [
            'trigger' => ['connector_id' => (int) $data['connector_id'], 'entity' => $data['entity']],
            'action' => ['module' => $data['module']],
        ];
    }
}
