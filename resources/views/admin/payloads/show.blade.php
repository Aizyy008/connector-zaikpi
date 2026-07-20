<x-app-layout title="Payload #{{ $payload->id }}" active="payloads">
    <x-slot:breadcrumb>Webhooks / Payload Logs / #{{ $payload->id }}</x-slot:breadcrumb>
    <x-slot:actions>
        <a href="{{ route('admin.payloads.index') }}" class="rounded-xl border border-border bg-panel px-4 py-2.5 font-bold">Back to logs</a>
    </x-slot:actions>

    <section class="grid gap-4 lg:grid-cols-[1fr_1.6fr]">
        <x-card>
            <div class="flex items-center justify-between gap-3 mb-4">
                <h2 class="text-lg font-extrabold">Metadata</h2>
                <x-badge :color="$payload->statusColor()">{{ ucfirst($payload->status) }}</x-badge>
            </div>
            <dl class="grid gap-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-muted">Endpoint</dt><dd class="font-semibold">{{ $payload->endpoint?->name ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Connector</dt><dd class="font-semibold">{{ $payload->connector?->name ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Workspace</dt><dd class="font-semibold">{{ $currentWorkspace->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Received</dt><dd class="font-semibold"><x-datetime :value="$payload->received_at" /></dd></div>
            </dl>
            @if ($payload->error)
                <div class="mt-4 rounded-xl border px-3 py-2.5 text-sm"
                     style="background: color-mix(in srgb, var(--red) 12%, transparent); color: var(--red); border-color: color-mix(in srgb, var(--red) 25%, transparent);">
                    {{ $payload->error }}
                </div>
            @endif

            @if ($payload->headers)
                <div class="mt-4">
                    <div class="text-xs font-extrabold uppercase tracking-wide text-muted mb-2">Headers</div>
                    <pre class="rounded-xl border border-border bg-white/[.02] p-4 text-xs overflow-auto max-h-64">{{ json_encode($payload->headers, JSON_PRETTY_PRINT) }}</pre>
                </div>
            @endif
        </x-card>

        <div class="grid gap-4">
            <x-card>
                <div class="text-xs font-extrabold uppercase tracking-wide text-muted mb-2">Raw payload</div>
                <pre class="rounded-xl border border-border bg-white/[.02] p-4 text-xs overflow-auto max-h-72">{{ $payload->raw_payload }}</pre>
            </x-card>

            <x-card>
                <div class="text-xs font-extrabold uppercase tracking-wide text-muted mb-2">Parsed payload</div>
                <pre class="rounded-xl border border-border bg-white/[.02] p-4 text-xs overflow-auto max-h-72">{{ $payload->parsed_payload ? json_encode($payload->parsed_payload, JSON_PRETTY_PRINT) : '— not parsed —' }}</pre>
            </x-card>

            @if ($preview)
                <x-card>
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-xs font-extrabold uppercase tracking-wide text-muted">Mapping preview{{ $payload->endpoint?->entity ? ' · '.$payload->endpoint->entity : '' }}</div>
                        @if (! empty($preview['missing']))
                            <x-badge color="amber">{{ count($preview['missing']) }} missing source field(s)</x-badge>
                        @endif
                    </div>
                    @if (empty($preview['mapped']))
                        <p class="text-sm text-muted">No field mappings configured for this connector/entity yet.
                            <a href="{{ route('admin.mappings.index') }}" class="text-blue font-bold">Configure mappings →</a>
                        </p>
                    @else
                        <pre class="rounded-xl border border-border bg-white/[.02] p-4 text-xs overflow-auto max-h-72">{{ json_encode($preview['mapped'], JSON_PRETTY_PRINT) }}</pre>
                    @endif
                </x-card>
            @endif
        </div>
    </section>
</x-app-layout>
