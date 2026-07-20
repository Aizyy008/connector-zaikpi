@php $editing = $flow->exists; @endphp
<x-app-layout :title="$editing ? 'Edit Flow' : 'New Flow'" active="flows">
    <x-slot:breadcrumb>Flows / {{ $editing ? 'Edit' : 'Create' }}</x-slot:breadcrumb>
    <x-slot:subtitle>Define a trigger (connector + entity) and the action module to run.</x-slot:subtitle>

    <x-card>
        <form method="POST" action="{{ $editing ? route('admin.flows.update', $flow) : route('admin.flows.store') }}" class="space-y-5">
            @csrf
            @if ($editing) @method('PUT') @endif

            <x-input label="Name" name="name" :value="$flow->name" required placeholder="Paid order → invoice" />

            <div class="rounded-2xl border border-border bg-white/[.02] p-4">
                <div class="text-xs font-extrabold uppercase tracking-wide text-muted mb-3">Trigger</div>
                <div class="grid sm:grid-cols-2 gap-5">
                    <x-select label="Connector" name="connector_id" :selected="$flow->triggerConnectorId()" required
                              :options="$connectors->pluck('name', 'id')->all()" />
                    <x-input label="Entity" name="entity" :value="$flow->triggerEntity()" required placeholder="orders" />
                </div>
            </div>

            <div class="rounded-2xl border border-border bg-white/[.02] p-4">
                <div class="text-xs font-extrabold uppercase tracking-wide text-muted mb-3">Action</div>
                @if ($actionModules->isEmpty())
                    <p class="text-sm text-muted">No enabled action modules. Enable one under Modules first.</p>
                @else
                    <x-select label="Action module" name="module" :selected="$flow->actionModule()" required
                              :options="$actionModules->pluck('name', 'slug')->all()" />
                @endif
            </div>

            <x-select label="Status" name="status" :selected="$flow->status" required
                      :options="['draft' => 'Draft', 'active' => 'Active', 'paused' => 'Paused']" />

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="rounded-xl px-5 py-2.5 font-extrabold text-white shadow-lg"
                        style="background: linear-gradient(135deg, var(--blue), var(--purple));">{{ $editing ? 'Save Changes' : 'Create Flow' }}</button>
                <a href="{{ route('admin.flows.index') }}" class="rounded-xl border border-border bg-panel px-5 py-2.5 font-bold">Cancel</a>
            </div>
        </form>
    </x-card>
</x-app-layout>
