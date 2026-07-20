@php
    // Map domain statuses to the shared x-badge semantic colors.
    $badge = [
        'healthy' => 'green', 'valid' => 'green', 'processed' => 'green', 'completed' => 'green', 'active' => 'green',
        'processing' => 'blue', 'received' => 'blue', 'info' => 'blue',
        'warning' => 'amber', 'warn' => 'amber', 'review' => 'amber', 'pending' => 'amber', 'held' => 'amber',
        'disconnected' => 'red', 'invalid' => 'red', 'failed' => 'red', 'bad' => 'red',
    ];
    $badgeColor = fn ($s) => $badge[$s] ?? 'gray';

    $overall = $connectorHealth['disconnected'] > 0 || $jobs['failed'] > 0
        ? ['label' => 'Attention', 'color' => 'red']
        : ($connectorHealth['warning'] > 0 || $mappings['review'] > 0
            ? ['label' => 'Warning', 'color' => 'amber']
            : ['label' => 'Healthy', 'color' => 'green']);
@endphp

<x-app-layout title="Dashboard" active="dashboard">
    <x-slot:breadcrumb>Dashboard / Operational Summary</x-slot:breadcrumb>
    <x-slot:subtitle>
        Operational summary for <strong>{{ $context['workspace'] }}</strong>. Environment context, queue
        pressure, connector health and risks — scoped to your active workspace and permissions.
    </x-slot:subtitle>

    <x-slot:actions>
        @can('connectors.view')
            <a href="{{ route('admin.connectors.index') }}" class="rounded-xl border border-border bg-panel px-4 py-2.5 text-sm font-bold">Connectors</a>
        @endcan
        @can('queue.view')
            <a href="{{ route('admin.queue.index') }}"
               class="rounded-xl px-4 py-2.5 text-sm font-extrabold text-white shadow-lg"
               style="background: linear-gradient(135deg, var(--blue), var(--purple));">Open Queue</a>
        @endcan
    </x-slot:actions>

    {{-- Environment context --}}
    <x-card>
        <div class="flex items-start justify-between gap-4 mb-4">
            <div>
                <h2 class="text-xl font-extrabold tracking-tight">Environment Context</h2>
                <p class="text-sm text-muted mt-1">Where you are working right now.</p>
            </div>
            <x-badge color="blue">Live Context</x-badge>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-border bg-white/[.02] p-4">
                <div class="text-xs font-extrabold uppercase tracking-wide text-muted">Environment</div>
                <div class="mt-1.5 text-xl font-extrabold">{{ $context['environment'] }}</div>
                <div class="text-sm text-muted mt-1">Active tenant · protected actions</div>
            </div>
            <div class="rounded-2xl border border-border bg-white/[.02] p-4">
                <div class="text-xs font-extrabold uppercase tracking-wide text-muted">Workspace</div>
                <div class="mt-1.5 text-xl font-extrabold">{{ $context['workspace'] }}</div>
                <div class="text-sm text-muted mt-1">{{ $context['connectors'] }} connector(s) installed</div>
            </div>
            <div class="rounded-2xl border border-border bg-white/[.02] p-4">
                <div class="text-xs font-extrabold uppercase tracking-wide text-muted">Your Access</div>
                <div class="mt-1.5 text-xl font-extrabold">{{ auth()->user()->is_super_admin ? 'Super Admin' : (auth()->user()->roleIn($workspace)?->name ?? 'Member') }}</div>
                <div class="text-sm text-muted mt-1">Role in this workspace</div>
            </div>
            <div class="rounded-2xl border border-border bg-white/[.02] p-4">
                <div class="text-xs font-extrabold uppercase tracking-wide text-muted">Last Payload</div>
                <div class="mt-1.5 text-xl font-extrabold">{{ $context['last_payload']?->diffForHumans() ?? 'None yet' }}</div>
                <div class="text-sm text-muted mt-1">Most recent webhook received</div>
            </div>
        </div>
    </x-card>

    {{-- Stat grid --}}
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Connectors" :value="$connectorHealth['total']"
                     :meta="$connectorHealth['healthy'].' healthy · '.$connectorHealth['warning'].' warning · '.$connectorHealth['disconnected'].' down'" />
        <x-stat-card label="Processed Today" :value="$jobs['completed_today']"
                     :meta="$jobs['completed'].' completed · '.$jobs['failed'].' failed all-time'" />
        <x-stat-card label="Queue Backlog" :value="$jobs['pending'] + $jobs['processing']"
                     :meta="$jobs['pending'].' pending · '.$jobs['processing'].' processing'" />
        <x-stat-card label="Awaiting Approval" :value="$jobs['held']"
                     meta="Held execution jobs" />
        <x-stat-card label="Webhook Payloads" :value="$payloads['total']"
                     :meta="$payloads['valid'].' valid · '.$payloads['invalid'].' invalid'" />
        <x-stat-card label="Active Flows" :value="$flows['active']"
                     :meta="$flows['total'].' total automations'" />
        <x-stat-card label="Field Mappings" :value="$mappings['total']"
                     :meta="$mappings['review'].' need review'" />
        <x-stat-card label="Modules" :value="$modules['enabled']"
                     :meta="$modules['total'].' registered · '.$modules['unhealthy'].' unhealthy'" />
    </section>

    {{-- Queue pressure snapshot --}}
    <x-card>
        <div class="flex items-start justify-between gap-4 mb-4">
            <div>
                <h2 class="text-xl font-extrabold tracking-tight">Queue Pressure Snapshot</h2>
                <p class="text-sm text-muted mt-1">Execution backlog, in-flight work, failures and approvals.</p>
            </div>
            <x-badge :color="$jobs['pending'] > 0 ? 'amber' : 'green'">{{ $jobs['pending'] }} waiting</x-badge>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['Queued Now', $jobs['pending'], 'Pending execution jobs'],
                ['Processing', $jobs['processing'], 'Currently running'],
                ['Failed', $jobs['failed'], 'Retryable from the queue'],
                ['Awaiting Approval', $jobs['held'], 'Held for a reviewer'],
            ] as [$label, $value, $meta])
                <div class="rounded-2xl border border-border bg-white/[.02] p-4">
                    <div class="text-xs font-extrabold uppercase tracking-wide text-muted">{{ $label }}</div>
                    <div class="mt-1.5 text-2xl font-extrabold">{{ $value }}</div>
                    <div class="text-sm text-muted mt-1">{{ $meta }}</div>
                </div>
            @endforeach
        </div>
    </x-card>

    {{-- Connector health + readiness --}}
    <section class="grid gap-4 lg:grid-cols-[1.1fr_0.9fr]">
        <x-card>
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-xl font-extrabold tracking-tight">Connector Health</h2>
                    <p class="text-sm text-muted mt-1">Quick view — deep config lives in Connector detail.</p>
                </div>
                <x-badge color="blue">{{ $connectorHealth['total'] }} installed</x-badge>
            </div>

            <div class="grid gap-3">
                @forelse ($connectors as $connector)
                    <div class="rounded-2xl border border-border bg-white/[.02] p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-extrabold">{{ $connector->name }}</h3>
                                <p class="text-sm text-muted mt-0.5">{{ $connector->last_health_status ?: 'No health check run yet.' }}</p>
                            </div>
                            <x-badge :color="$badgeColor($connector->status)">{{ ucfirst($connector->status) }}</x-badge>
                        </div>
                        <div class="flex flex-wrap gap-2 mt-3">
                            @if ($connector->provider)
                                <span class="rounded-full border border-border bg-chip-bg px-2.5 py-1 text-xs font-bold text-chip-text">{{ $connector->provider }}</span>
                            @endif
                            <span class="rounded-full border border-border bg-chip-bg px-2.5 py-1 text-xs font-bold text-chip-text">{{ Str::headline($connector->type) }}</span>
                            @if ($connector->role && $connector->role !== 'none')
                                <span class="rounded-full border border-border bg-chip-bg px-2.5 py-1 text-xs font-bold text-chip-text">{{ Str::headline($connector->role) }}</span>
                            @endif
                        </div>
                        @can('connectors.view')
                            <div class="mt-3">
                                <a href="{{ route('admin.connectors.show', $connector) }}" class="text-sm font-bold text-blue">View connector →</a>
                            </div>
                        @endcan
                    </div>
                @empty
                    <p class="text-sm text-muted rounded-2xl border border-border bg-white/[.02] p-4">
                        No connectors in this workspace yet.
                        @can('connectors.manage')
                            <a href="{{ route('admin.connectors.create') }}" class="font-bold text-blue">Add one →</a>
                        @endcan
                    </p>
                @endforelse
            </div>
        </x-card>

        <x-card>
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-xl font-extrabold tracking-tight">Operational Readiness</h2>
                    <p class="text-sm text-muted mt-1">Execution, delivery, mapping and connector rates.</p>
                </div>
                <x-badge :color="$overall['color']">{{ $overall['label'] }}</x-badge>
            </div>

            <div class="grid gap-3">
                @foreach ($readiness as $r)
                    <div class="rounded-2xl border border-border bg-white/[.02] p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="font-bold">{{ $r['label'] }}</div>
                                <div class="text-xs text-muted mt-0.5">{{ $r['desc'] }}</div>
                            </div>
                            <strong class="text-lg">{{ $r['pct'] }}%</strong>
                        </div>
                        <div class="mt-2.5 h-2.5 rounded-full overflow-hidden border border-border bg-bg-soft">
                            <span class="block h-full rounded-full" style="width: {{ $r['pct'] }}%; background: linear-gradient(90deg, var(--blue), var(--purple));"></span>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>
    </section>

    {{-- Alerts + quick actions --}}
    <section class="grid gap-4 lg:grid-cols-2">
        <x-card>
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-xl font-extrabold tracking-tight">Alerts</h2>
                    <p class="text-sm text-muted mt-1">Issues detected in this workspace right now.</p>
                </div>
                <x-badge :color="count($alerts) ? 'amber' : 'green'">{{ count($alerts) }} active</x-badge>
            </div>

            <div class="grid gap-3">
                @forelse ($alerts as $alert)
                    <a href="{{ $alert['route'] }}" class="block rounded-2xl border border-border bg-white/[.02] p-4 transition hover:border-border-strong">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-bold">{{ $alert['title'] }}</h3>
                                <p class="text-sm text-muted mt-0.5">{{ $alert['subtitle'] }}</p>
                            </div>
                            <x-badge :color="$badgeColor($alert['level'])">{{ $alert['level'] === 'bad' ? 'Critical' : ucfirst($alert['level']) }}</x-badge>
                        </div>
                    </a>
                @empty
                    <p class="text-sm text-muted rounded-2xl border border-border bg-white/[.02] p-4">
                        All clear — no active alerts in this workspace.
                    </p>
                @endforelse
            </div>
        </x-card>

        <x-card>
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-xl font-extrabold tracking-tight">Quick Actions</h2>
                    <p class="text-sm text-muted mt-1">Jump to the modules you can access.</p>
                </div>
                <x-badge color="purple">Safe Actions</x-badge>
            </div>

            <div class="grid gap-2.5">
                @php
                    $quick = [
                        ['queue.view', 'admin.queue.index', 'Queue & Logs', 'Monitor and retry execution jobs.'],
                        ['flows.view', 'admin.flows.index', 'Flows / Automations', 'Review triggers, mappings and actions.'],
                        ['mappings.view', 'admin.mappings.index', 'Field Mappings', 'Inspect drift and value rules.'],
                        ['payloads.view', 'admin.payloads.index', 'Payload Logs', 'Inspect raw and parsed webhooks.'],
                        ['webhooks.view', 'admin.webhooks.index', 'Webhook Endpoints', 'Manage inbound endpoints & secrets.'],
                        ['audit.view', 'admin.audit.index', 'Audit Trail', 'Review who changed what.'],
                    ];
                @endphp
                @foreach ($quick as [$perm, $route, $label, $desc])
                    @can($perm)
                        <a href="{{ route($route) }}" class="flex items-center justify-between gap-3 rounded-2xl border border-border bg-white/[.02] p-4 transition hover:border-border-strong">
                            <div>
                                <div class="font-bold">{{ $label }}</div>
                                <div class="text-sm text-muted mt-0.5">{{ $desc }}</div>
                            </div>
                            <span class="text-blue font-bold">→</span>
                        </a>
                    @endcan
                @endforeach
            </div>
        </x-card>
    </section>

    {{-- Recent operational events --}}
    <x-card :padded="false">
        <div class="flex items-start justify-between gap-4 p-5 pb-0">
            <div>
                <h2 class="text-xl font-extrabold tracking-tight">Recent Operational Events</h2>
                <p class="text-sm text-muted mt-1">Latest execution jobs and webhook payloads in this workspace.</p>
            </div>
            <x-badge color="blue">Latest</x-badge>
        </div>
        <div class="overflow-x-auto mt-4">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-muted" style="background: var(--table-head);">
                        <th class="px-5 py-3 font-extrabold">Time</th>
                        <th class="px-5 py-3 font-extrabold">Event</th>
                        <th class="px-5 py-3 font-extrabold">Module</th>
                        <th class="px-5 py-3 font-extrabold">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recent as $event)
                        <tr class="border-t border-border">
                            <td class="px-5 py-3 whitespace-nowrap text-muted"><x-datetime :value="$event['time']" format="M d, H:i" :tz="false" /></td>
                            <td class="px-5 py-3">{{ $event['event'] }}</td>
                            <td class="px-5 py-3 text-muted">{{ $event['module'] }}</td>
                            <td class="px-5 py-3"><x-badge :color="$badgeColor($event['status'])">{{ ucfirst($event['status']) }}</x-badge></td>
                        </tr>
                    @empty
                        <tr class="border-t border-border">
                            <td colspan="4" class="px-5 py-6 text-center text-muted">No operational events yet in this workspace.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-app-layout>
