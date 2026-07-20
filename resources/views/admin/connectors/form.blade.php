@php
    $editing = $connector->exists;
    use App\Http\Controllers\Admin\ConnectorController;
    $typeOptions = collect(ConnectorController::TYPES)->mapWithKeys(fn ($t) => [$t => Str::headline($t)])->all();
    $roleOptions = collect(ConnectorController::ROLES)->mapWithKeys(fn ($r) => [$r => Str::headline($r)])->all();
@endphp
<x-app-layout :title="$editing ? 'Edit Connector' : 'Add Connector'" active="connectors">
    <x-slot:breadcrumb>Connectors / {{ $editing ? 'Edit' : 'Create' }}</x-slot:breadcrumb>

    <x-card>
        <form method="POST"
              action="{{ $editing ? route('admin.connectors.update', $connector) : route('admin.connectors.store') }}"
              class="space-y-5">
            @csrf
            @if ($editing) @method('PUT') @endif

            <div class="grid sm:grid-cols-2 gap-5">
                <x-input label="Name" name="name" :value="$connector->name" required placeholder="CommerceApp" />
                <x-input label="Provider" name="provider" :value="$connector->provider" placeholder="e.g. Shopify" />
                <x-select label="Type" name="type" :selected="$connector->type" required :options="$typeOptions" />
                <x-select label="Role" name="role" :selected="$connector->role" required :options="$roleOptions" />
            </div>

            <label class="flex items-center gap-2 text-sm select-none">
                <input type="checkbox" name="enabled" value="1" class="rounded border-border" @checked(old('enabled', $connector->enabled ?? true))>
                Enabled
            </label>

            @if ($editing)
                <p class="text-xs text-muted">Slug: <span class="font-mono">{{ $connector->slug }}</span>. Credentials & health are managed on the detail page.</p>
            @else
                <p class="text-xs text-muted">After creating, add credentials and run a health check on the detail page.</p>
            @endif

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="rounded-xl px-5 py-2.5 font-extrabold text-white shadow-lg"
                        style="background: linear-gradient(135deg, var(--blue), var(--purple));">
                    {{ $editing ? 'Save Changes' : 'Create Connector' }}
                </button>
                <a href="{{ $editing ? route('admin.connectors.show', $connector) : route('admin.connectors.index') }}"
                   class="rounded-xl border border-border bg-panel px-5 py-2.5 font-bold">Cancel</a>
            </div>
        </form>
    </x-card>
</x-app-layout>
