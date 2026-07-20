@php
    use App\Http\Controllers\Admin\ConnectorCredentialController;
    $credTypes = collect(ConnectorCredentialController::TYPES)->mapWithKeys(fn ($t) => [$t => Str::headline($t)])->all();
@endphp
<x-app-layout :title="$connector->name" active="connectors">
    <x-slot:breadcrumb>Connectors / {{ $connector->name }}</x-slot:breadcrumb>
    <x-slot:actions>
        @can('connectors.test')
            <form method="POST" action="{{ route('admin.connectors.test', $connector) }}">
                @csrf
                <button class="rounded-xl border border-border bg-panel px-4 py-2.5 font-bold">Run Health Check</button>
            </form>
        @endcan
        @can('connectors.write')
            <a href="{{ route('admin.connectors.edit', $connector) }}" class="rounded-xl border border-border bg-panel px-4 py-2.5 font-bold">Edit</a>
        @endcan
    </x-slot:actions>

    <section class="grid gap-4 lg:grid-cols-[1fr_1.4fr]">
        {{-- Overview / health --}}
        <x-card>
            <div class="flex items-center justify-between gap-3 mb-4">
                <h2 class="text-lg font-extrabold">Overview</h2>
                <x-badge :color="$connector->statusColor()">{{ Str::headline($connector->status) }}</x-badge>
            </div>
            <dl class="grid gap-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-muted">Type</dt><dd class="font-semibold">{{ Str::headline($connector->type) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Role</dt><dd class="font-semibold">{{ Str::headline($connector->role) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Provider</dt><dd class="font-semibold">{{ $connector->provider ?: '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Enabled</dt><dd class="font-semibold">{{ $connector->enabled ? 'Yes' : 'No' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Last checked</dt><dd class="font-semibold">{{ $connector->health_checked_at?->diffForHumans() ?? 'Never' }}</dd></div>
            </dl>
            @if ($connector->last_health_status)
                <div class="mt-4 rounded-xl border border-border bg-white/[.02] px-3 py-2.5 text-sm text-muted">
                    {{ $connector->last_health_status }}
                </div>
            @endif

            @can('connectors.write')
                <form method="POST" action="{{ route('admin.connectors.destroy', $connector) }}" class="mt-5"
                      onsubmit="return confirm('Delete this connector and its credentials?')">
                    @csrf @method('DELETE')
                    <button class="text-sm font-bold" style="color: var(--red);">Delete connector</button>
                </form>
            @endcan
        </x-card>

        {{-- Credentials --}}
        <x-card>
            <div class="flex items-center justify-between gap-3 mb-1">
                <h2 class="text-lg font-extrabold">Credentials</h2>
                <x-badge color="blue">Encrypted at rest</x-badge>
            </div>
            <p class="text-sm text-muted mb-4">Secrets are AES-encrypted and shown masked. Leave the value blank when editing to keep the current secret.</p>

            <div class="grid gap-3">
                @forelse ($connector->credentials as $cred)
                    <div class="rounded-2xl border border-border bg-white/[.02] p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="font-bold">{{ $cred->key }}</div>
                                <div class="text-xs text-muted">{{ Str::headline($cred->type) }} · <span class="font-mono">{{ $cred->masked() }}</span>
                                    @if ($cred->isExpired())<x-badge color="red">Expired</x-badge>@endif
                                </div>
                            </div>
                        </div>
                        @can('credentials.manage')
                            <form method="POST" action="{{ route('admin.connectors.credentials.update', [$connector, $cred]) }}"
                                  class="mt-3 grid sm:grid-cols-[1fr_1fr_auto] gap-2 items-end">
                                @csrf @method('PUT')
                                <x-select label="Type" name="type" :selected="$cred->type" :options="$credTypes" />
                                <x-input label="New value (blank = keep)" name="value" type="password" placeholder="••••••••" autocomplete="off" />
                                <div class="flex gap-2">
                                    <button class="rounded-xl border border-border bg-panel px-4 py-2.5 font-bold">Update</button>
                                </div>
                            </form>
                            <form method="POST" action="{{ route('admin.connectors.credentials.destroy', [$connector, $cred]) }}"
                                  class="mt-2" onsubmit="return confirm('Delete this credential?')">
                                @csrf @method('DELETE')
                                <button class="text-xs font-bold" style="color: var(--red);">Delete credential</button>
                            </form>
                        @endcan
                    </div>
                @empty
                    <p class="text-sm text-muted">No credentials yet.</p>
                @endforelse
            </div>

            @can('credentials.manage')
                <div class="mt-5 rounded-2xl border border-dashed border-border p-4">
                    <div class="text-xs font-extrabold uppercase tracking-wide text-muted mb-3">Add credential</div>
                    <form method="POST" action="{{ route('admin.connectors.credentials.store', $connector) }}" class="grid sm:grid-cols-2 gap-3">
                        @csrf
                        <x-input label="Key" name="key" required placeholder="api_token" />
                        <x-select label="Type" name="type" :options="$credTypes" />
                        <x-input label="Value (secret)" name="value" type="password" required placeholder="paste secret" autocomplete="off" />
                        <x-input label="Expires at" name="expires_at" type="date" />
                        <div class="sm:col-span-2">
                            <button class="rounded-xl px-5 py-2.5 font-extrabold text-white shadow-lg"
                                    style="background: linear-gradient(135deg, var(--blue), var(--purple));">Save Credential</button>
                        </div>
                    </form>
                </div>
            @endcan
        </x-card>
    </section>
</x-app-layout>
