<x-app-layout title="Webhooks" active="webhooks">
    <x-slot:breadcrumb>Webhooks / Endpoints</x-slot:breadcrumb>
    <x-slot:subtitle>Inbound endpoints for <strong>{{ $currentWorkspace->name }}</strong>. Payloads are signature-verified, validated, and logged.</x-slot:subtitle>
    @can('webhooks.manage')
        <x-slot:actions>
            <a href="{{ route('admin.webhooks.create') }}"
               class="rounded-xl px-4 py-2.5 font-extrabold text-white shadow-lg"
               style="background: linear-gradient(135deg, var(--blue), var(--purple));">New Endpoint</a>
        </x-slot:actions>
    @endcan

    <x-card :padded="false">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-muted" style="background: var(--table-head);">
                        <th class="px-4 py-3 font-extrabold">Name</th>
                        <th class="px-4 py-3 font-extrabold">Public URL</th>
                        <th class="px-4 py-3 font-extrabold">Connector</th>
                        <th class="px-4 py-3 font-extrabold">Entity</th>
                        <th class="px-4 py-3 font-extrabold">Signed</th>
                        <th class="px-4 py-3 font-extrabold">Payloads</th>
                        <th class="px-4 py-3 font-extrabold">Status</th>
                        <th class="px-4 py-3 font-extrabold text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($endpoints as $e)
                        <tr class="border-t border-border">
                            <td class="px-4 py-3 font-bold">{{ $e->name }}</td>
                            <td class="px-4 py-3"><code class="text-xs">{{ url($e->publicPath()) }}</code></td>
                            <td class="px-4 py-3 text-muted">{{ $e->connector?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-muted">{{ $e->entity ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <x-badge :color="$e->hasSecret() ? 'green' : 'gray'">{{ $e->hasSecret() ? 'HMAC' : 'None' }}</x-badge>
                            </td>
                            <td class="px-4 py-3 text-muted">{{ $e->payloads_count }}</td>
                            <td class="px-4 py-3"><x-badge :color="$e->enabled ? 'green' : 'gray'">{{ $e->enabled ? 'Enabled' : 'Disabled' }}</x-badge></td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    @can('webhooks.manage')
                                        <a href="{{ route('admin.webhooks.edit', $e) }}" class="rounded-lg border border-border bg-panel px-3 py-1.5 font-bold">Edit</a>
                                        <form method="POST" action="{{ route('admin.webhooks.regenerate', $e) }}">
                                            @csrf
                                            <button class="rounded-lg border border-border bg-panel px-3 py-1.5 font-bold">Rotate secret</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.webhooks.destroy', $e) }}" onsubmit="return confirm('Delete endpoint?')">
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
                        <tr><td colspan="8" class="px-4 py-8 text-center text-muted">No endpoints yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-card>
        <h2 class="text-sm font-extrabold uppercase tracking-wide text-muted mb-2">Send a test payload</h2>
        <pre class="rounded-xl border border-border bg-white/[.02] p-4 text-xs overflow-auto">curl -X POST {{ url('/webhooks/&lt;slug&gt;') }} \
  -H "Content-Type: application/json" \
  -H "X-Signature: $(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')" \
  -d "$BODY"</pre>
    </x-card>
</x-app-layout>
