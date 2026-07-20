<x-app-layout title="Workspaces" active="workspaces">
    <x-slot:breadcrumb>Settings / Workspaces</x-slot:breadcrumb>
    <x-slot:subtitle>Isolated tenants/projects. Members and roles are scoped per workspace.</x-slot:subtitle>
    @can('workspaces.manage')
        <x-slot:actions>
            <a href="{{ route('admin.workspaces.create') }}"
               class="rounded-xl px-4 py-2.5 font-extrabold text-white shadow-lg"
               style="background: linear-gradient(135deg, var(--blue), var(--purple));">New Workspace</a>
        </x-slot:actions>
    @endcan

    <x-card :padded="false">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-muted" style="background: var(--table-head);">
                        <th class="px-4 py-3 font-extrabold">Name</th>
                        <th class="px-4 py-3 font-extrabold">Slug</th>
                        <th class="px-4 py-3 font-extrabold">Environment</th>
                        <th class="px-4 py-3 font-extrabold">Status</th>
                        <th class="px-4 py-3 font-extrabold">Members</th>
                        <th class="px-4 py-3 font-extrabold text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($workspaces as $ws)
                        <tr class="border-t border-border">
                            <td class="px-4 py-3 font-bold">{{ $ws->name }}</td>
                            <td class="px-4 py-3 text-muted font-mono text-xs">{{ $ws->slug }}</td>
                            <td class="px-4 py-3"><x-badge color="blue">{{ ucfirst($ws->environment) }}</x-badge></td>
                            <td class="px-4 py-3">
                                <x-badge :color="$ws->status === 'active' ? 'green' : 'gray'">{{ ucfirst($ws->status) }}</x-badge>
                            </td>
                            <td class="px-4 py-3 text-muted">{{ $ws->users_count }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    @can('workspaces.manage')
                                        <a href="{{ route('admin.workspaces.edit', $ws) }}"
                                           class="rounded-lg border border-border bg-panel px-3 py-1.5 font-bold">Edit</a>
                                        <form method="POST" action="{{ route('admin.workspaces.destroy', $ws) }}"
                                              onsubmit="return confirm('Delete this workspace?')">
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
                        <tr><td colspan="6" class="px-4 py-8 text-center text-muted">No workspaces yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-app-layout>
