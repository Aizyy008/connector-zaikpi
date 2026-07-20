<x-app-layout title="Connectors" active="connectors">
    <x-slot:breadcrumb>Connectors / Registry</x-slot:breadcrumb>
    <x-slot:subtitle>External application connections for <strong>{{ $currentWorkspace->name }}</strong>. Health states: Healthy / Warning / Disconnected.</x-slot:subtitle>
    @can('connectors.write')
        <x-slot:actions>
            <a href="{{ route('admin.connectors.create') }}"
               class="rounded-xl px-4 py-2.5 font-extrabold text-white shadow-lg"
               style="background: linear-gradient(135deg, var(--blue), var(--purple));">Add Connector</a>
        </x-slot:actions>
    @endcan

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Total" :value="$stats['total']" meta="Installed and tracked" />
        <x-stat-card label="Healthy" :value="$stats['healthy']" meta="No action required" />
        <x-stat-card label="Warning" :value="$stats['warning']" meta="Review recommended" />
        <x-stat-card label="Disconnected" :value="$stats['disconnected']" meta="Reconnect / inspect" />
    </section>

    <x-card :padded="false">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-muted" style="background: var(--table-head);">
                        <th class="px-4 py-3 font-extrabold">Connector</th>
                        <th class="px-4 py-3 font-extrabold">Type</th>
                        <th class="px-4 py-3 font-extrabold">Role</th>
                        <th class="px-4 py-3 font-extrabold">Credentials</th>
                        <th class="px-4 py-3 font-extrabold">Status</th>
                        <th class="px-4 py-3 font-extrabold text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($connectors as $c)
                        <tr class="border-t border-border">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.connectors.show', $c) }}" class="font-bold hover:underline">{{ $c->name }}</a>
                                @unless ($c->enabled)<x-badge color="gray">Disabled</x-badge>@endunless
                                <div class="text-xs text-muted">{{ $c->provider }}</div>
                            </td>
                            <td class="px-4 py-3 text-muted">{{ Str::headline($c->type) }}</td>
                            <td class="px-4 py-3 text-muted">{{ Str::headline($c->role) }}</td>
                            <td class="px-4 py-3 text-muted">{{ $c->credentials_count }}</td>
                            <td class="px-4 py-3"><x-badge :color="$c->statusColor()">{{ Str::headline($c->status) }}</x-badge></td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('admin.connectors.show', $c) }}" class="rounded-lg border border-border bg-panel px-3 py-1.5 font-bold">Open</a>
                                    @can('connectors.test')
                                        <form method="POST" action="{{ route('admin.connectors.test', $c) }}">
                                            @csrf
                                            <button class="rounded-lg border border-border bg-panel px-3 py-1.5 font-bold">Test</button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-muted">No connectors yet in this workspace.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-app-layout>
