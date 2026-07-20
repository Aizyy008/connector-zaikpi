@php
    use App\Http\Controllers\Admin\FieldMappingController;
    $editing = $mapping->exists;
    $transformOptions = collect(FieldMappingController::TRANSFORMS)->mapWithKeys(fn ($t) => [$t => ucfirst($t)])->all();
@endphp
<x-app-layout :title="$editing ? 'Edit Mapping' : 'New Mapping'" active="mappings">
    <x-slot:breadcrumb>Mappings / {{ $editing ? 'Edit' : 'Create' }}</x-slot:breadcrumb>

    <x-card>
        <form method="POST" action="{{ $editing ? route('admin.mappings.update', $mapping) : route('admin.mappings.store') }}" class="space-y-5">
            @csrf
            @if ($editing) @method('PUT') @endif

            <div class="grid sm:grid-cols-2 gap-5">
                <x-input label="Entity" name="entity" :value="$mapping->entity" required placeholder="orders" />
                <x-select label="Connector (optional)" name="connector_id" :selected="$mapping->connector_id"
                          :options="['' => 'Any connector'] + $connectors->pluck('name', 'id')->all()" />
            </div>

            <div class="grid sm:grid-cols-2 gap-5">
                <x-input label="Source field (dot-path)" name="source_field" :value="$mapping->source_field" required placeholder="customer.email" />
                <x-input label="Target field (dot-path)" name="target_field" :value="$mapping->target_field" required placeholder="customer_reference" />
            </div>

            <div class="grid sm:grid-cols-2 gap-5">
                <x-select label="Transform" name="transform_type" :selected="$mapping->transform['type'] ?? 'none'" :options="$transformOptions" />
                <x-input label="Transform value (for “default”)" name="transform_value" :value="$mapping->transform['value'] ?? ''" placeholder="optional" />
            </div>

            <x-select label="Status" name="status" :selected="$mapping->status" required
                      :options="['validated' => 'Validated', 'review' => 'Review', 'warning' => 'Warning']" />

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="rounded-xl px-5 py-2.5 font-extrabold text-white shadow-lg"
                        style="background: linear-gradient(135deg, var(--blue), var(--purple));">{{ $editing ? 'Save Changes' : 'Create Mapping' }}</button>
                <a href="{{ route('admin.mappings.index') }}" class="rounded-xl border border-border bg-panel px-5 py-2.5 font-bold">Cancel</a>
            </div>
        </form>
    </x-card>
</x-app-layout>
