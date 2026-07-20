<x-app-layout title="Mappings" active="mappings">
    <x-slot:breadcrumb>Mappings / Field Mapping</x-slot:breadcrumb>
    <x-slot:subtitle>Map incoming payload fields to canonical / action-input fields. Applied live in the payload preview.</x-slot:subtitle>
    @can('mappings.manage')
        <x-slot:actions>
            <a href="{{ route('admin.mappings.create') }}"
               class="rounded-xl px-4 py-2.5 font-extrabold text-white shadow-lg"
               style="background: linear-gradient(135deg, var(--blue), var(--purple));">New Mapping</a>
        </x-slot:actions>
    @endcan

    @forelse ($groups as $entity => $mappings)
        <x-card :padded="false">
            <div class="px-4 py-3 font-extrabold text-sm uppercase tracking-wide text-muted border-b border-border">{{ $entity }}</div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-muted" style="background: var(--table-head);">
                            <th class="px-4 py-3 font-extrabold">Source field</th>
                            <th class="px-4 py-3 font-extrabold">Target field</th>
                            <th class="px-4 py-3 font-extrabold">Transform</th>
                            <th class="px-4 py-3 font-extrabold">Connector</th>
                            <th class="px-4 py-3 font-extrabold">Status</th>
                            <th class="px-4 py-3 font-extrabold text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($mappings as $m)
                            <tr class="border-t border-border">
                                <td class="px-4 py-3"><code class="text-xs">{{ $m->source_field }}</code></td>
                                <td class="px-4 py-3"><code class="text-xs">{{ $m->target_field }}</code></td>
                                <td class="px-4 py-3 text-muted text-xs">{{ $m->transform['type'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-muted">{{ $m->connector?->name ?? 'Any' }}</td>
                                <td class="px-4 py-3"><x-badge :color="$m->statusColor()">{{ ucfirst($m->status) }}</x-badge></td>
                                <td class="px-4 py-3 text-right">
                                    @can('mappings.manage')
                                        <div class="flex items-center justify-end gap-2">
                                            <a href="{{ route('admin.mappings.edit', $m) }}" class="rounded-lg border border-border bg-panel px-3 py-1.5 font-bold">Edit</a>
                                            <form method="POST" action="{{ route('admin.mappings.destroy', $m) }}" onsubmit="return confirm('Delete mapping?')">
                                                @csrf @method('DELETE')
                                                <button class="rounded-lg border border-border px-3 py-1.5 font-bold" style="color: var(--red);">Delete</button>
                                            </form>
                                        </div>
                                    @else
                                        <span class="text-muted text-xs">View only</span>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @empty
        <x-card><p class="text-sm text-muted">No field mappings yet.</p></x-card>
    @endforelse
</x-app-layout>
