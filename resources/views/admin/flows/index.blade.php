<x-app-layout title="Flows / Automations" active="flows">
    <x-slot:breadcrumb>Flows / Automations</x-slot:breadcrumb>
    <x-slot:subtitle>Trigger → action automations. An active flow turns matching webhook payloads into queued execution jobs.</x-slot:subtitle>
    @can('flows.manage')
        <x-slot:actions>
            <a href="{{ route('admin.flows.create') }}"
               class="rounded-xl px-4 py-2.5 font-extrabold text-white shadow-lg"
               style="background: linear-gradient(135deg, var(--blue), var(--purple));">New Flow</a>
        </x-slot:actions>
    @endcan

    <x-card :padded="false">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-muted" style="background: var(--table-head);">
                        <th class="px-4 py-3 font-extrabold">Flow</th>
                        <th class="px-4 py-3 font-extrabold">Trigger</th>
                        <th class="px-4 py-3 font-extrabold">Action</th>
                        <th class="px-4 py-3 font-extrabold">Runs</th>
                        <th class="px-4 py-3 font-extrabold">Status</th>
                        <th class="px-4 py-3 font-extrabold text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($flows as $flow)
                        <tr class="border-t border-border">
                            <td class="px-4 py-3 font-bold">{{ $flow->name }}</td>
                            <td class="px-4 py-3 text-muted">{{ $connectorNames[$flow->triggerConnectorId()] ?? '—' }} · <span class="font-mono text-xs">{{ $flow->triggerEntity() }}</span></td>
                            <td class="px-4 py-3"><code class="text-xs">{{ $flow->actionModule() }}</code></td>
                            <td class="px-4 py-3 text-muted">{{ $flow->execution_jobs_count }}</td>
                            <td class="px-4 py-3"><x-badge :color="$flow->statusColor()">{{ ucfirst($flow->status) }}</x-badge></td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    @can('flows.manage')
                                        <form method="POST" action="{{ route('admin.flows.toggle', $flow) }}">
                                            @csrf @method('PATCH')
                                            <button class="rounded-lg border border-border bg-panel px-3 py-1.5 font-bold">{{ $flow->status === 'active' ? 'Pause' : 'Activate' }}</button>
                                        </form>
                                        <a href="{{ route('admin.flows.edit', $flow) }}" class="rounded-lg border border-border bg-panel px-3 py-1.5 font-bold">Edit</a>
                                        <form method="POST" action="{{ route('admin.flows.destroy', $flow) }}" onsubmit="return confirm('Delete flow?')">
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
                        <tr><td colspan="6" class="px-4 py-8 text-center text-muted">No flows yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-app-layout>
