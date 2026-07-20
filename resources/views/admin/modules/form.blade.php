@php
    $editing = $module->exists;
    $typeOptions = collect($types)->mapWithKeys(fn ($t) => [$t => ucfirst($t)])->all();
    $execOptions = collect($executionMethods)->mapWithKeys(fn ($m) => [$m => ucfirst($m)])->all();
    $scopesValue = is_array($module->scopes) ? implode(', ', $module->scopes) : '';
    $inputValue = filled($module->input_schema) ? json_encode($module->input_schema, JSON_PRETTY_PRINT) : '';
    $outputValue = filled($module->output_schema) ? json_encode($module->output_schema, JSON_PRETTY_PRINT) : '';
@endphp
<x-app-layout :title="$editing ? 'Edit Module' : 'New Module'" active="modules">
    <x-slot:breadcrumb>Modules / {{ $editing ? 'Edit' : 'Create' }}</x-slot:breadcrumb>
    <x-slot:subtitle>
        Define a module's registry metadata. Modules defined in code
        (<span class="font-mono text-xs">ModuleContract</span>) are executable; a
        module created here has no code behind it yet, so it is marked
        <strong>unavailable</strong> until an implementation is registered.
    </x-slot:subtitle>

    <x-card>
        <form method="POST"
              action="{{ $editing ? route('admin.modules.update', $module) : route('admin.modules.store') }}"
              class="space-y-5">
            @csrf
            @if ($editing) @method('PUT') @endif

            <div class="grid sm:grid-cols-2 gap-5">
                <x-input label="Name" name="name" :value="$module->name" required placeholder="Create Invoice" />
                @if ($codeBacked)
                    <div>
                        <label class="mb-2 block text-xs font-bold uppercase tracking-wide text-muted">Slug</label>
                        <input type="text" value="{{ $module->slug }}" disabled
                               class="w-full rounded-xl border border-border bg-panel-2/60 px-3.5 py-2.5 text-muted font-mono outline-none">
                        <p class="mt-1.5 text-xs text-muted">Defined in code — slug is locked so flows keep resolving.</p>
                    </div>
                @else
                    <x-input label="Slug" name="slug" :value="$module->slug" required placeholder="businessapp.create_invoice"
                             hint="Lowercase letters, numbers, dot, dash, underscore. Referenced by flows." />
                @endif
                <x-select label="Type" name="type" :selected="$module->type" required :options="$typeOptions" />
                <x-select label="Execution Method" name="execution_method" :selected="$module->execution_method" required :options="$execOptions" />
                <x-input label="Version" name="version" :value="$module->version ?: '1.0.0'" placeholder="1.0.0" />
                <x-input label="Scopes (comma-separated)" name="scopes" :value="$scopesValue" placeholder="flows.execute, webhooks.view" />
            </div>

            <div>
                <label class="mb-2 block text-xs font-bold uppercase tracking-wide text-muted">Description</label>
                <textarea name="description" rows="2"
                          class="w-full rounded-xl border border-border bg-panel-2 px-3.5 py-2.5 text-text outline-none focus:border-blue focus:ring-2 focus:ring-blue/30"
                          placeholder="What this module does.">{{ old('description', $module->description) }}</textarea>
                @error('description') <p class="mt-1.5 text-sm" style="color: var(--red);">{{ $message }}</p> @enderror
            </div>

            <div class="grid sm:grid-cols-2 gap-5">
                <div>
                    <label class="mb-2 block text-xs font-bold uppercase tracking-wide text-muted">Input schema (JSON)</label>
                    <textarea name="input_schema" rows="6"
                              class="w-full rounded-xl border border-border bg-panel-2 px-3.5 py-2.5 text-text font-mono text-xs outline-none focus:border-blue focus:ring-2 focus:ring-blue/30"
                              placeholder='{"external_order_id": "string"}'>{{ old('input_schema', $inputValue) }}</textarea>
                    <p class="mt-1.5 text-xs text-muted">Declared fields are treated as required at execution time.</p>
                    @error('input_schema') <p class="mt-1.5 text-sm" style="color: var(--red);">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-2 block text-xs font-bold uppercase tracking-wide text-muted">Output schema (JSON)</label>
                    <textarea name="output_schema" rows="6"
                              class="w-full rounded-xl border border-border bg-panel-2 px-3.5 py-2.5 text-text font-mono text-xs outline-none focus:border-blue focus:ring-2 focus:ring-blue/30"
                              placeholder='{"invoice_reference": "string"}'>{{ old('output_schema', $outputValue) }}</textarea>
                    @error('output_schema') <p class="mt-1.5 text-sm" style="color: var(--red);">{{ $message }}</p> @enderror
                </div>
            </div>

            <label class="flex items-center gap-3 rounded-xl border border-border bg-white/[.02] px-4 py-3">
                <input type="hidden" name="enabled" value="0">
                <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $module->enabled)) class="h-4 w-4">
                <span class="font-bold">Enabled</span>
                <span class="text-sm text-muted">Disabled modules cannot be selected by new flows or executed.</span>
            </label>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="rounded-xl px-5 py-2.5 font-extrabold text-white shadow-lg"
                        style="background: linear-gradient(135deg, var(--blue), var(--purple));">
                    {{ $editing ? 'Save Changes' : 'Create Module' }}
                </button>
                <a href="{{ $editing ? route('admin.modules.show', $module) : route('admin.modules.index') }}" class="rounded-xl border border-border bg-panel px-5 py-2.5 font-bold">Cancel</a>
            </div>
        </form>
    </x-card>
</x-app-layout>
