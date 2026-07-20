@php $editing = $endpoint->exists; @endphp
<x-app-layout :title="$editing ? 'Edit Endpoint' : 'New Endpoint'" active="webhooks">
    <x-slot:breadcrumb>Webhooks / {{ $editing ? 'Edit' : 'Create' }}</x-slot:breadcrumb>

    <x-card>
        <form method="POST" action="{{ $editing ? route('admin.webhooks.update', $endpoint) : route('admin.webhooks.store') }}" class="space-y-5">
            @csrf
            @if ($editing) @method('PUT') @endif

            <x-input label="Name" name="name" :value="$endpoint->name" required placeholder="CommerceApp Orders" />

            <div class="grid sm:grid-cols-2 gap-5">
                <x-select label="Connector (optional)" name="connector_id" :selected="$endpoint->connector_id"
                          :options="['' => '— none —'] + $connectors->pluck('name', 'id')->all()" />
                <x-input label="Canonical entity (optional)" name="entity" :value="$endpoint->entity" placeholder="orders" />
            </div>

            <x-input label="Signature header" name="signature_header" :value="$endpoint->signature_header ?: 'X-Signature'" required
                     hint="Incoming HMAC-SHA256 signature is read from this header when a secret is set." />

            <label class="flex items-center gap-2 text-sm select-none">
                <input type="checkbox" name="enabled" value="1" class="rounded border-border" @checked(old('enabled', $endpoint->enabled ?? true))>
                Enabled
            </label>

            @if ($editing)
                <p class="text-xs text-muted">Public URL: <code>{{ url($endpoint->publicPath()) }}</code> · Secret: {{ $endpoint->hasSecret() ? 'set (use “Rotate secret” to reveal a new one)' : 'none' }}</p>
            @else
                <p class="text-xs text-muted">A signing secret is generated on creation and shown once.</p>
            @endif

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="rounded-xl px-5 py-2.5 font-extrabold text-white shadow-lg"
                        style="background: linear-gradient(135deg, var(--blue), var(--purple));">{{ $editing ? 'Save Changes' : 'Create Endpoint' }}</button>
                <a href="{{ route('admin.webhooks.index') }}" class="rounded-xl border border-border bg-panel px-5 py-2.5 font-bold">Cancel</a>
            </div>
        </form>
    </x-card>
</x-app-layout>
