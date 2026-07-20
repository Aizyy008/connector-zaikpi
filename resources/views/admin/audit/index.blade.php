<x-app-layout title="Audit Trail" active="audit">
    <x-slot:breadcrumb>Queue &amp; Logs / Audit Trail</x-slot:breadcrumb>
    <x-slot:subtitle>Append-only record of sensitive admin actions. Secrets are never captured in the change set.</x-slot:subtitle>

    <form method="GET" class="flex flex-wrap items-end gap-3">
        <div class="min-w-56">
            <label class="block text-xs font-bold uppercase tracking-wide text-muted mb-2">Action</label>
            <select name="action" onchange="this.form.submit()"
                    class="w-full rounded-xl border border-border bg-panel-2 px-3.5 py-2.5 text-text outline-none focus:border-blue">
                <option value="">All actions</option>
                @foreach ($actions as $a)
                    <option value="{{ $a }}" @selected($action === $a)>{{ $a }}</option>
                @endforeach
            </select>
        </div>
        @if ($action)
            <a href="{{ route('admin.audit.index') }}" class="rounded-xl border border-border bg-panel px-4 py-2.5 font-bold">Clear</a>
        @endif
    </form>

    <x-card :padded="false">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-muted" style="background: var(--table-head);">
                        <th class="px-4 py-3 font-extrabold">Time</th>
                        <th class="px-4 py-3 font-extrabold">Actor</th>
                        <th class="px-4 py-3 font-extrabold">Action</th>
                        <th class="px-4 py-3 font-extrabold">Subject</th>
                        <th class="px-4 py-3 font-extrabold">Changes</th>
                        <th class="px-4 py-3 font-extrabold">IP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr class="border-t border-border align-top">
                            <td class="px-4 py-3 text-muted whitespace-nowrap"><x-datetime :value="$log->created_at" /></td>
                            <td class="px-4 py-3">{{ $log->user?->name ?? 'system' }}</td>
                            <td class="px-4 py-3"><code class="text-xs">{{ $log->action }}</code></td>
                            <td class="px-4 py-3 text-muted text-xs">
                                @if ($log->auditable_type)
                                    {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}
                                @else — @endif
                            </td>
                            <td class="px-4 py-3">
                                @if ($log->changes)
                                    <details>
                                        <summary class="cursor-pointer text-blue text-xs font-bold">view</summary>
                                        <pre class="mt-2 rounded-lg border border-border bg-white/[.02] p-3 text-xs overflow-auto max-w-md">{{ json_encode($log->changes, JSON_PRETTY_PRINT) }}</pre>
                                    </details>
                                @else
                                    <span class="text-muted text-xs">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-muted text-xs">{{ $log->ip_address ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-muted">No audit entries yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    {{ $logs->links() }}
</x-app-layout>
