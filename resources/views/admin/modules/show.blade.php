<x-app-layout :title="$module->name" active="modules">
    <x-slot:breadcrumb>Modules / {{ $module->name }}</x-slot:breadcrumb>
    <x-slot:actions>
        @can('modules.manage')
            <form method="POST" action="{{ route('admin.modules.health', $module) }}">
                @csrf
                <button class="rounded-xl border border-border bg-panel px-4 py-2.5 font-bold">Run Health Check</button>
            </form>
            <form method="POST" action="{{ route('admin.modules.toggle', $module) }}">
                @csrf @method('PATCH')
                <button class="rounded-xl border border-border bg-panel px-4 py-2.5 font-bold">{{ $module->enabled ? 'Disable' : 'Enable' }}</button>
            </form>
        @endcan
    </x-slot:actions>

    <section class="grid gap-4 lg:grid-cols-[1fr_1.4fr]">
        <x-card>
            <h2 class="text-lg font-extrabold mb-4">Contract</h2>
            <dl class="grid gap-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-muted">Slug</dt><dd class="font-mono text-xs">{{ $module->slug }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Type</dt><dd class="font-semibold">{{ ucfirst($module->type) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Execution</dt><dd class="font-semibold">{{ ucfirst($module->execution_method) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Version</dt><dd class="font-semibold">{{ $module->version }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Health</dt>
                    <dd><x-badge :color="$module->health_status === 'healthy' ? 'green' : ($module->health_status === 'warning' ? 'amber' : 'red')">{{ ucfirst($module->health_status) }}</x-badge></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Enabled</dt>
                    <dd><x-badge :color="$module->enabled ? 'green' : 'gray'">{{ $module->enabled ? 'Yes' : 'No' }}</x-badge></dd></div>
            </dl>
            @if ($module->description)
                <p class="mt-4 text-sm text-muted leading-relaxed">{{ $module->description }}</p>
            @endif
            <div class="mt-4">
                <div class="text-xs font-extrabold uppercase tracking-wide text-muted mb-2">Actions</div>
                <div class="flex flex-wrap gap-2">
                    @forelse ($module->actions ?? [] as $action)
                        <x-badge color="blue">{{ $action }}</x-badge>
                    @empty
                        <span class="text-sm text-muted">None declared</span>
                    @endforelse
                </div>
            </div>
            <div class="mt-4">
                <div class="text-xs font-extrabold uppercase tracking-wide text-muted mb-2">Required scopes</div>
                <div class="flex flex-wrap gap-2">
                    @forelse ($module->scopes ?? [] as $scope)
                        <span class="font-mono text-xs rounded-full border border-border px-2.5 py-1">{{ $scope }}</span>
                    @empty
                        <span class="text-sm text-muted">None</span>
                    @endforelse
                </div>
            </div>
        </x-card>

        <x-card>
            <h2 class="text-lg font-extrabold mb-4">Input / Output Schema</h2>
            <div class="grid gap-4">
                <div>
                    <div class="text-xs font-extrabold uppercase tracking-wide text-muted mb-2">Input</div>
                    <pre class="rounded-xl border border-border bg-white/[.02] p-4 text-xs overflow-auto">{{ json_encode($module->input_schema ?: (object) [], JSON_PRETTY_PRINT) }}</pre>
                </div>
                <div>
                    <div class="text-xs font-extrabold uppercase tracking-wide text-muted mb-2">Output</div>
                    <pre class="rounded-xl border border-border bg-white/[.02] p-4 text-xs overflow-auto">{{ json_encode($module->output_schema ?: (object) [], JSON_PRETTY_PRINT) }}</pre>
                </div>
            </div>
        </x-card>
    </section>
</x-app-layout>
