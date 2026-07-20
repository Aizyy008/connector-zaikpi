<x-app-layout title="Users" active="users">
    <x-slot:breadcrumb>Settings / Users</x-slot:breadcrumb>
    <x-slot:subtitle>Accounts and their role within <strong>{{ $workspace->name }}</strong>. Roles are assigned per workspace.</x-slot:subtitle>
    @can('users.manage')
        <x-slot:actions>
            <a href="{{ route('admin.users.create') }}"
               class="rounded-xl px-4 py-2.5 font-extrabold text-white shadow-lg"
               style="background: linear-gradient(135deg, var(--blue), var(--purple));">New User</a>
        </x-slot:actions>
    @endcan

    <x-card :padded="false">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-muted" style="background: var(--table-head);">
                        <th class="px-4 py-3 font-extrabold">User</th>
                        <th class="px-4 py-3 font-extrabold">Email</th>
                        <th class="px-4 py-3 font-extrabold">Role (this workspace)</th>
                        <th class="px-4 py-3 font-extrabold">Status</th>
                        <th class="px-4 py-3 font-extrabold">Last login</th>
                        <th class="px-4 py-3 font-extrabold text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $u)
                        <tr class="border-t border-border">
                            <td class="px-4 py-3">
                                <div class="font-bold">{{ $u->name }}</div>
                                @if ($u->is_super_admin)<x-badge color="purple">Super Admin</x-badge>@endif
                            </td>
                            <td class="px-4 py-3 text-muted">{{ $u->email }}</td>
                            <td class="px-4 py-3">
                                @if ($u->is_super_admin)
                                    <span class="text-muted text-xs">All workspaces</span>
                                @elseif ($rolesByUser[$u->id] ?? null)
                                    <x-badge color="blue">{{ $rolesByUser[$u->id] }}</x-badge>
                                @else
                                    <span class="text-muted text-xs">Not a member</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <x-badge :color="$u->status === 'active' ? 'green' : ($u->status === 'invited' ? 'blue' : 'gray')">{{ ucfirst($u->status) }}</x-badge>
                            </td>
                            <td class="px-4 py-3 text-muted text-xs">{{ $u->last_login_at?->diffForHumans() ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    @can('users.manage')
                                        <a href="{{ route('admin.users.edit', $u) }}"
                                           class="rounded-lg border border-border bg-panel px-3 py-1.5 font-bold">Edit</a>
                                        <form method="POST" action="{{ route('admin.users.destroy', $u) }}"
                                              onsubmit="return confirm('Delete this user?')">
                                            @csrf @method('DELETE')
                                            <button class="rounded-lg border border-border px-3 py-1.5 font-bold" style="color: var(--red);">Delete</button>
                                        </form>
                                    @else
                                        <span class="text-muted text-xs">View only</span>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-muted">No users in this workspace.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-app-layout>
