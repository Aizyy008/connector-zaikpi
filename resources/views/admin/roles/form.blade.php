@php
    $editing = $role->exists;
    $isSuper = $role->slug === 'super_admin';
@endphp
<x-app-layout :title="$editing ? 'Edit Role' : 'New Role'" active="roles">
    <x-slot:breadcrumb>Settings / Roles &amp; Permissions / {{ $editing ? 'Edit' : 'Create' }}</x-slot:breadcrumb>
    <x-slot:subtitle>Toggle the permissions this role grants. Changes apply immediately to everyone with the role.</x-slot:subtitle>

    <form method="POST" action="{{ $editing ? route('admin.roles.update', $role) : route('admin.roles.store') }}" class="grid gap-5">
        @csrf
        @if ($editing) @method('PUT') @endif

        <x-card>
            <div class="grid gap-5">
                <x-input label="Role name" name="name" :value="$role->name" required
                         placeholder="e.g. Support Agent" :readonly="$role->is_system"
                         :hint="$role->is_system ? 'System role name is fixed.' : null" />
                <x-input label="Description" name="description" :value="$role->description"
                         placeholder="Short summary of what this role can do" />
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center justify-between gap-3 mb-4">
                <h2 class="text-lg font-extrabold">Permissions</h2>
                @if (! $isSuper)
                    <div class="flex gap-2 text-xs">
                        <button type="button" data-bulk="all" class="rounded-lg border border-border bg-panel px-3 py-1.5 font-bold">Select all</button>
                        <button type="button" data-bulk="none" class="rounded-lg border border-border bg-panel px-3 py-1.5 font-bold">Clear</button>
                    </div>
                @endif
            </div>

            @if ($isSuper)
                <p class="text-sm text-muted">Super Admin bypasses every permission check, so its permission set is not editable.</p>
            @else
                <div class="grid gap-5 sm:grid-cols-2">
                    @foreach ($permissionGroups as $group => $permissions)
                        <div class="rounded-2xl border border-border bg-white/[.02] p-4">
                            <div class="text-xs font-extrabold uppercase tracking-wide text-muted mb-3">{{ ucfirst($group) }}</div>
                            <div class="grid gap-2.5">
                                @foreach ($permissions as $permission)
                                    <label class="flex items-center gap-2.5 text-sm cursor-pointer">
                                        <input type="checkbox" name="permissions[]" value="{{ $permission->id }}"
                                               class="perm-box rounded border-border"
                                               @checked(in_array($permission->id, old('permissions', $selected), false))>
                                        <span class="font-mono text-xs">{{ $permission->slug }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-card>

        <div class="flex items-center gap-3">
            <button type="submit"
                    class="rounded-xl px-5 py-2.5 font-extrabold text-white shadow-lg"
                    style="background: linear-gradient(135deg, var(--blue), var(--purple));">
                {{ $editing ? 'Save Changes' : 'Create Role' }}
            </button>
            <a href="{{ route('admin.roles.index') }}" class="rounded-xl border border-border bg-panel px-5 py-2.5 font-bold">Cancel</a>
        </div>
    </form>

    <script>
        document.querySelectorAll('[data-bulk]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var on = btn.dataset.bulk === 'all';
                document.querySelectorAll('.perm-box').forEach(function (box) { box.checked = on; });
            });
        });
    </script>
</x-app-layout>
