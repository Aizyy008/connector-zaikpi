<x-app-layout title="Modules" active="modules">
    <x-slot:breadcrumb>Modules / Registry</x-slot:breadcrumb>
    <x-slot:subtitle>Contract-driven capability registry. Add a module by implementing <span class="font-mono text-xs">ModuleContract</span> and running <span class="font-mono text-xs">modules:sync</span> — no core changes.</x-slot:subtitle>
    @can('modules.manage')
        <x-slot:actions>
            <form method="POST" action="{{ route('admin.modules.sync') }}">
                @csrf
                <button class="rounded-xl border border-border bg-panel px-4 py-2.5 font-bold">Sync from code</button>
            </form>
            <a href="{{ route('admin.modules.create') }}"
               class="rounded-xl px-4 py-2.5 font-extrabold text-white shadow-lg"
               style="background: linear-gradient(135deg, var(--blue), var(--purple));">Add Module</a>
        </x-slot:actions>
    @endcan

    <x-card :padded="false">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-muted" style="background: var(--table-head);">
                        <th class="px-4 py-3 font-extrabold">Module</th>
                        <th class="px-4 py-3 font-extrabold">Type</th>
                        <th class="px-4 py-3 font-extrabold">Execution</th>
                        <th class="px-4 py-3 font-extrabold">Health</th>
                        <th class="px-4 py-3 font-extrabold">Enabled</th>
                        <th class="px-4 py-3 font-extrabold text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($modules as $m)
                        <tr class="border-t border-border">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.modules.show', $m) }}" class="font-bold hover:underline">{{ $m->name }}</a>
                                <div class="text-xs text-muted font-mono">{{ $m->slug }}</div>
                            </td>
                            <td class="px-4 py-3"><x-badge color="purple">{{ ucfirst($m->type) }}</x-badge></td>
                            <td class="px-4 py-3 text-muted">{{ ucfirst($m->execution_method) }}</td>
                            <td class="px-4 py-3">
                                <x-badge :color="$m->health_status === 'healthy' ? 'green' : ($m->health_status === 'warning' ? 'amber' : 'red')">{{ ucfirst($m->health_status) }}</x-badge>
                            </td>
                            <td class="px-4 py-3">
                                <x-badge :color="$m->enabled ? 'green' : 'gray'">{{ $m->enabled ? 'Enabled' : 'Disabled' }}</x-badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('admin.modules.show', $m) }}" class="rounded-lg border border-border bg-panel px-3 py-1.5 font-bold">View</a>
                                    @can('modules.manage')
                                        <a href="{{ route('admin.modules.edit', $m) }}" class="rounded-lg border border-border bg-panel px-3 py-1.5 font-bold">Edit</a>
                                        <form method="POST" action="{{ route('admin.modules.toggle', $m) }}">
                                            @csrf @method('PATCH')
                                            <button class="rounded-lg border border-border bg-panel px-3 py-1.5 font-bold">{{ $m->enabled ? 'Disable' : 'Enable' }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.modules.destroy', $m) }}"
                                              onsubmit="return confirm('Delete this module? (Code-defined modules cannot be deleted.)');">
                                            @csrf @method('DELETE')
                                            <button class="rounded-lg border border-border bg-panel px-3 py-1.5 font-bold" style="color: var(--red);">Delete</button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-muted">No modules registered. Run <span class="font-mono">modules:sync</span>.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-app-layout>
