<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $workspaces = $user->is_super_admin
            ? Workspace::withCount('users')->orderBy('name')->get()
            : $user->workspaces()->withCount('users')->orderBy('name')->get();

        return view('admin.workspaces.index', compact('workspaces'));
    }

    public function create(): View
    {
        return view('admin.workspaces.form', ['workspace' => new Workspace(['status' => 'active', 'environment' => 'production'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $workspace = Workspace::create($data);
        AuditLog::record('workspace.created', $workspace, ['name' => $workspace->name], $workspace->id);

        return redirect()->route('admin.workspaces.index')->with('status', "Workspace “{$workspace->name}” created.");
    }

    public function edit(Workspace $workspace): View
    {
        return view('admin.workspaces.form', compact('workspace'));
    }

    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        $workspace->update($this->validated($request, $workspace));
        AuditLog::record('workspace.updated', $workspace, $workspace->getChanges(), $workspace->id);

        return redirect()->route('admin.workspaces.index')->with('status', "Workspace “{$workspace->name}” updated.");
    }

    public function destroy(Workspace $workspace): RedirectResponse
    {
        AuditLog::record('workspace.deleted', $workspace, ['name' => $workspace->name], $workspace->id);
        $workspace->delete();

        return redirect()->route('admin.workspaces.index')->with('status', 'Workspace deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Workspace $workspace = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'environment' => ['required', Rule::in(['production', 'staging', 'development'])],
            'status' => ['required', Rule::in(['active', 'disabled'])],
        ]);

        $data['slug'] = $workspace?->slug ?? Str::slug($data['name']).'-'.Str::lower(Str::random(4));

        return $data;
    }
}
