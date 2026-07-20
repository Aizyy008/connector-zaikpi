@php $editing = $workspace->exists; @endphp
<x-app-layout :title="$editing ? 'Edit Workspace' : 'New Workspace'" active="workspaces">
    <x-slot:breadcrumb>Settings / Workspaces / {{ $editing ? 'Edit' : 'Create' }}</x-slot:breadcrumb>

    <x-card>
        <form method="POST"
              action="{{ $editing ? route('admin.workspaces.update', $workspace) : route('admin.workspaces.store') }}"
              class="space-y-5">
            @csrf
            @if ($editing) @method('PUT') @endif

            <x-input label="Name" name="name" :value="$workspace->name" required placeholder="Core Operations" />

            <div class="grid sm:grid-cols-2 gap-5">
                <x-select label="Environment" name="environment" :selected="$workspace->environment" required
                          :options="['production' => 'Production', 'staging' => 'Staging', 'development' => 'Development']" />
                <x-select label="Status" name="status" :selected="$workspace->status" required
                          :options="['active' => 'Active', 'disabled' => 'Disabled']" />
            </div>

            @if ($editing)
                <p class="text-xs text-muted">Slug: <span class="font-mono">{{ $workspace->slug }}</span> (immutable)</p>
            @endif

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="rounded-xl px-5 py-2.5 font-extrabold text-white shadow-lg"
                        style="background: linear-gradient(135deg, var(--blue), var(--purple));">
                    {{ $editing ? 'Save Changes' : 'Create Workspace' }}
                </button>
                <a href="{{ route('admin.workspaces.index') }}" class="rounded-xl border border-border bg-panel px-5 py-2.5 font-bold">Cancel</a>
            </div>
        </form>
    </x-card>
</x-app-layout>
