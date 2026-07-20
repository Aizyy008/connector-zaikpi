<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * User management with role assignment scoped to the current workspace.
 */
class UserController extends Controller
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function index(Request $request): View
    {
        $workspace = $this->context->get();

        // Super admin sees everyone; others see members of the current workspace.
        $users = $request->user()->is_super_admin
            ? User::orderBy('name')->get()
            : $workspace->users()->orderBy('name')->get();

        // Map user_id => role in the current workspace (for display).
        $rolesByUser = $workspace->users()->get()
            ->mapWithKeys(fn ($u) => [$u->id => Role::find($u->pivot->role_id)?->name]);

        return view('admin.users.index', compact('users', 'rolesByUser', 'workspace'));
    }

    public function create(): View
    {
        return view('admin.users.form', [
            'user' => new User(['status' => 'active']),
            'roles' => Role::orderBy('name')->get(),
            'workspaces' => $this->assignableWorkspaces(),
            'memberships' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateUser($request);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'username' => $data['username'] ?? null,
            'password' => Hash::make($data['password']),
            'status' => $data['status'],
            'is_super_admin' => false,
        ]);

        $user->workspaces()->sync($this->membershipSync($data['memberships']));

        AuditLog::record('user.created', $user, ['email' => $user->email], $this->context->id());

        return redirect()->route('admin.users.index')->with('status', "User “{$user->name}” created.");
    }

    public function edit(User $user): View
    {
        $memberships = $user->workspaces()->get()
            ->map(fn ($w) => ['workspace_id' => $w->id, 'role_id' => $w->pivot->role_id])
            ->all();

        return view('admin.users.form', [
            'user' => $user,
            'roles' => Role::orderBy('name')->get(),
            'workspaces' => $this->assignableWorkspaces(),
            'memberships' => $memberships,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validateUser($request, $user);

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'username' => $data['username'] ?? null,
            'status' => $data['status'],
        ]);

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        $user->workspaces()->sync($this->membershipSync($data['memberships']));

        // Never log the password hash: record only non-sensitive changed fields
        // plus a boolean marker if the password was rotated. (AuditLog also
        // scrubs defensively, but we keep the call site clean too.)
        $changes = Arr::except($user->getChanges(), ['password', 'remember_token']);
        if (! empty($data['password'])) {
            $changes['password_changed'] = true;
        }

        AuditLog::record('user.updated', $user, $changes, $this->context->id());

        return redirect()->route('admin.users.index')->with('status', "User “{$user->name}” updated.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        if ($user->is_super_admin && User::where('is_super_admin', true)->count() <= 1) {
            return back()->with('error', 'Cannot delete the last super admin.');
        }

        AuditLog::record('user.deleted', $user, ['email' => $user->email], $this->context->id());
        $user->delete();

        return redirect()->route('admin.users.index')->with('status', 'User deleted.');
    }

    /**
     * Active workspaces this admin may associate a user with. Anyone with
     * users.manage can assign any active workspace (see access decision).
     */
    private function assignableWorkspaces()
    {
        return Workspace::where('status', 'active')->orderBy('name')->get();
    }

    /**
     * Shared validation for store/update. `memberships` is a list of
     * {workspace_id, role_id} rows; at least one is required so every user
     * belongs to a workspace.
     */
    private function validateUser(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'username' => ['nullable', 'string', 'max:60', Rule::unique('users', 'username')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
            'status' => ['required', Rule::in(['active', 'invited', 'disabled'])],
            'memberships' => ['required', 'array', 'min:1'],
            'memberships.*.workspace_id' => ['required', Rule::exists('workspaces', 'id')],
            'memberships.*.role_id' => ['required', Rule::exists('roles', 'id')],
        ]);
    }

    /**
     * Collapse the submitted membership rows into a sync payload
     * (workspace_id => ['role_id' => …]). A workspace listed twice keeps the
     * last role, so a user has one role per workspace.
     */
    private function membershipSync(array $memberships): array
    {
        $sync = [];

        foreach ($memberships as $row) {
            $sync[(int) $row['workspace_id']] = ['role_id' => (int) $row['role_id']];
        }

        return $sync;
    }
}
