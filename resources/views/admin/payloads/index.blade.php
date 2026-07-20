<x-app-layout title="Payload Logs" active="payloads">
    <x-slot:breadcrumb>Webhooks / Payload Logs</x-slot:breadcrumb>
    <x-slot:subtitle>Inbound webhook payloads with raw + parsed views and processing status.</x-slot:subtitle>

    <div class="flex flex-wrap gap-2">
        @php $all = ['' => 'All', 'valid' => 'Valid', 'invalid' => 'Invalid', 'received' => 'Received', 'processed' => 'Processed', 'failed' => 'Failed']; @endphp
        @foreach ($all as $key => $label)
            <a href="{{ route('admin.payloads.index', array_filter(['status' => $key])) }}"
               class="rounded-xl border px-3 py-1.5 text-sm font-bold {{ (string) $status === (string) $key ? 'border-blue text-blue' : 'border-border text-muted' }}">
                {{ $label }}@isset($counts[$key]) <span class="opacity-70">({{ $counts[$key] }})</span>@endisset
            </a>
        @endforeach
    </div>

    <x-card :padded="false">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-muted" style="background: var(--table-head);">
                        <th class="px-4 py-3 font-extrabold">Received</th>
                        <th class="px-4 py-3 font-extrabold">Endpoint</th>
                        <th class="px-4 py-3 font-extrabold">Connector</th>
                        <th class="px-4 py-3 font-extrabold">Status</th>
                        <th class="px-4 py-3 font-extrabold">Note</th>
                        <th class="px-4 py-3 font-extrabold text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($payloads as $p)
                        <tr class="border-t border-border">
                            <td class="px-4 py-3 text-muted whitespace-nowrap"><x-datetime :value="$p->received_at" /></td>
                            <td class="px-4 py-3">{{ $p->endpoint?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-muted">{{ $p->connector?->name ?? '—' }}</td>
                            <td class="px-4 py-3"><x-badge :color="$p->statusColor()">{{ ucfirst($p->status) }}</x-badge></td>
                            <td class="px-4 py-3 text-muted text-xs max-w-xs truncate">{{ $p->error ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('admin.payloads.show', $p) }}" class="rounded-lg border border-border bg-panel px-3 py-1.5 font-bold">Inspect</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-muted">No payloads recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    {{ $payloads->links() }}
</x-app-layout>
