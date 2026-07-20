<x-app-layout title="Execution Job #{{ $job->id }}" active="queue">
    <x-slot:breadcrumb>Queue &amp; Logs / Job #{{ $job->id }}</x-slot:breadcrumb>
    <x-slot:actions>
        @can('queue.retry')
            @if (in_array($job->status, ['failed', 'held'], true))
                <form method="POST" action="{{ route('admin.queue.retry', $job) }}">
                    @csrf
                    <button class="rounded-xl border border-border bg-panel px-4 py-2.5 font-bold">Retry Job</button>
                </form>
            @endif
        @endcan
        <a href="{{ route('admin.queue.index') }}" class="rounded-xl border border-border bg-panel px-4 py-2.5 font-bold">Back to queue</a>
    </x-slot:actions>

    <section class="grid gap-4 lg:grid-cols-[1fr_1.6fr]">
        <x-card>
            <div class="flex items-center justify-between gap-3 mb-4">
                <h2 class="text-lg font-extrabold">Execution</h2>
                <x-badge :color="$job->statusColor()">{{ ucfirst($job->status) }}</x-badge>
            </div>
            <dl class="grid gap-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-muted">Flow</dt><dd class="font-semibold">{{ $job->flow?->name ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Action module</dt><dd class="font-mono text-xs">{{ $job->type }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Connector</dt><dd class="font-semibold">{{ $job->connector?->name ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Source payload</dt>
                    <dd class="font-semibold">
                        @if ($job->payload)
                            <a href="{{ route('admin.payloads.show', $job->payload) }}" class="text-blue">#{{ $job->payload_id }}</a>
                        @else — @endif
                    </dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Attempts</dt><dd class="font-semibold">{{ $job->attempts }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Started</dt><dd class="font-semibold"><x-datetime :value="$job->started_at" /></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Finished</dt><dd class="font-semibold"><x-datetime :value="$job->finished_at" /></dd></div>
            </dl>
            @if ($job->error)
                <div class="mt-4 rounded-xl border px-3 py-2.5 text-sm"
                     style="background: color-mix(in srgb, var(--red) 12%, transparent); color: var(--red); border-color: color-mix(in srgb, var(--red) 25%, transparent);">
                    {{ $job->error }}
                </div>
            @endif
        </x-card>

        <div class="grid gap-4">
            <x-card>
                <div class="text-xs font-extrabold uppercase tracking-wide text-muted mb-2">Input</div>
                <pre class="rounded-xl border border-border bg-white/[.02] p-4 text-xs overflow-auto max-h-72">{{ json_encode($job->input ?: (object) [], JSON_PRETTY_PRINT) }}</pre>
            </x-card>
            <x-card>
                <div class="text-xs font-extrabold uppercase tracking-wide text-muted mb-2">Result</div>
                <pre class="rounded-xl border border-border bg-white/[.02] p-4 text-xs overflow-auto max-h-72">{{ $job->result ? json_encode($job->result, JSON_PRETTY_PRINT) : '— no result —' }}</pre>
            </x-card>
        </div>
    </section>
</x-app-layout>
