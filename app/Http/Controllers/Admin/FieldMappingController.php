<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Connector;
use App\Models\FieldMapping;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FieldMappingController extends Controller
{
    public const TRANSFORMS = ['none', 'lowercase', 'uppercase', 'trim', 'default'];

    public function __construct(private readonly WorkspaceContext $context) {}

    public function index(): View
    {
        $mappings = FieldMapping::with('connector')->orderBy('entity')->orderBy('source_field')->get();

        return view('admin.mappings.index', ['groups' => $mappings->groupBy('entity')]);
    }

    public function create(): View
    {
        return view('admin.mappings.form', [
            'mapping' => new FieldMapping(['status' => 'review']),
            'connectors' => Connector::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $mapping = FieldMapping::create($this->validated($request));
        AuditLog::record('mapping.created', $mapping, $mapping->only('entity', 'source_field', 'target_field'), $this->context->id());

        return redirect()->route('admin.mappings.index')->with('status', 'Mapping created.');
    }

    public function edit(FieldMapping $mapping): View
    {
        return view('admin.mappings.form', [
            'mapping' => $mapping,
            'connectors' => Connector::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, FieldMapping $mapping): RedirectResponse
    {
        $mapping->update($this->validated($request));
        AuditLog::record('mapping.updated', $mapping, $mapping->getChanges(), $this->context->id());

        return redirect()->route('admin.mappings.index')->with('status', 'Mapping updated.');
    }

    public function destroy(FieldMapping $mapping): RedirectResponse
    {
        AuditLog::record('mapping.deleted', $mapping, $mapping->only('entity', 'source_field'), $this->context->id());
        $mapping->delete();

        return redirect()->route('admin.mappings.index')->with('status', 'Mapping deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'connector_id' => ['nullable', 'integer', Rule::exists('connectors', 'id')->where('workspace_id', $this->context->id())],
            'entity' => ['required', 'string', 'max:80'],
            'source_field' => ['required', 'string', 'max:190'],
            'target_field' => ['required', 'string', 'max:190'],
            'transform_type' => ['required', Rule::in(self::TRANSFORMS)],
            'transform_value' => ['nullable', 'string', 'max:190'],
            'status' => ['required', Rule::in(['validated', 'review', 'warning'])],
        ]);

        $transform = $data['transform_type'] === 'none'
            ? null
            : array_filter(['type' => $data['transform_type'], 'value' => $data['transform_value'] ?? null], fn ($v) => $v !== null);

        return [
            'connector_id' => $data['connector_id'] ?? null,
            'entity' => $data['entity'],
            'source_field' => $data['source_field'],
            'target_field' => $data['target_field'],
            'transform' => $transform,
            'status' => $data['status'],
        ];
    }
}
