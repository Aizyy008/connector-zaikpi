<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View
    {
        $roles = Role::with('permissions')->orderBy('name')->get();
        $permissionGroups = Permission::orderBy('group')->orderBy('slug')->get()->groupBy('group');

        return view('admin.roles.index', compact('roles', 'permissionGroups'));
    }

    public function create(): View
    {
        return view('admin.roles.form', [
            'role' => new Role,
            'permissionGroups' => $this->groups(),
            'selected' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validate($request);

        $role = Role::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
            'description' => $data['description'] ?? null,
            'is_system' => false,
        ]);

        $role->permissions()->sync($data['permissions'] ?? []);
        AuditLog::record('role.created', $role, ['name' => $role->name, 'permissions' => count($data['permissions'] ?? [])]);

        return redirect()->route('admin.roles.index')->with('status', "Role “{$role->name}” created.");
    }

    public function edit(Role $role): View
    {
        return view('admin.roles.form', [
            'role' => $role,
            'permissionGroups' => $this->groups(),
            'selected' => $role->permissions->pluck('id')->all(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $this->validate($request, $role);

        // Slug + is_system are immutable for system roles; name/description editable.
        $role->update([
            'name' => $role->is_system ? $role->name : $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        // Super Admin bypasses all gates; its permission set is not meaningful.
        if ($role->slug !== 'super_admin') {
            $role->permissions()->sync($data['permissions'] ?? []);
        }

        AuditLog::record('role.updated', $role, ['permissions' => count($data['permissions'] ?? [])]);

        return redirect()->route('admin.roles.index')->with('status', "Role “{$role->name}” updated.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return back()->with('error', 'System roles cannot be deleted.');
        }

        if ($role->users()->exists()) {
            return back()->with('error', 'This role is assigned to users and cannot be deleted.');
        }

        AuditLog::record('role.deleted', $role, ['name' => $role->name]);
        $role->delete();

        return redirect()->route('admin.roles.index')->with('status', 'Role deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validate(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:200'],
            'permissions' => ['array'],
            'permissions.*' => [Rule::exists('permissions', 'id')],
        ]);
    }

    private function groups()
    {
        return Permission::orderBy('group')->orderBy('slug')->get()->groupBy('group');
    }
}
