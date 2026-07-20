@php
    $roleSlugs = $roles->mapWithKeys(fn ($r) => [$r->id => $r->permissions->pluck('slug')->all()]);
@endphp
<x-app-layout title="Roles & Permissions" active="roles">
    <x-slot:breadcrumb>Settings / Roles &amp; Permissions</x-slot:breadcrumb>
    <x-slot:subtitle>The permission matrix that governs every backend action and UI control. Super Admin bypasses all checks.</x-slot:subtitle>
    @can('roles.manage')
        <x-slot:actions>
            <a href="{{ route('admin.roles.create') }}"
               class="rounded-xl px-4 py-2.5 font-extrabold text-white shadow-lg"
               style="background: linear-gradient(135deg, var(--blue), var(--purple));">New Role</a>
        </x-slot:actions>
    @endcan

    {{-- Role summary cards --}}
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($roles as $role)
            <x-card>
                <div class="flex items-center justify-between gap-2">
                    <h3 class="font-extrabold">{{ $role->name }}</h3>
                    @if ($role->is_system)<x-badge color="gray">System</x-badge>@endif
                </div>
                <p class="text-sm text-muted mt-1.5 leading-relaxed">{{ $role->description }}</p>
                <div class="mt-3 text-xs text-muted">
                    <span class="font-extrabold text-text">{{ $role->slug === 'super_admin' ? 'All' : count($roleSlugs[$role->id]) }}</span> permissions
                </div>
                @can('roles.manage')
                    <div class="mt-4 flex items-center gap-2">
                        <a href="{{ route('admin.roles.edit', $role) }}"
                           class="rounded-lg border border-border bg-panel px-3 py-1.5 text-sm font-bold">Edit</a>
                        @unless ($role->is_system)
                            <form method="POST" action="{{ route('admin.roles.destroy', $role) }}"
                                  onsubmit="return confirm('Delete this role?')">
                                @csrf @method('DELETE')
                                <button class="rounded-lg border border-border px-3 py-1.5 text-sm font-bold" style="color: var(--red);">Delete</button>
                            </form>
                        @endunless
                    </div>
                @endcan
            </x-card>
        @endforeach
    </section>

    {{-- Permission matrix --}}
    <x-card :padded="false">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-muted" style="background: var(--table-head);">
                        <th class="px-4 py-3 font-extrabold">Permission</th>
                        @foreach ($roles as $role)
                            <th class="px-4 py-3 font-extrabold text-center">{{ $role->name }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($permissionGroups as $group => $permissions)
                        <tr class="border-t border-border">
                            <td class="px-4 py-2 font-extrabold text-xs uppercase tracking-wide text-muted"
                                colspan="{{ $roles->count() + 1 }}" style="background: color-mix(in srgb, var(--blue) 5%, transparent);">
                                {{ ucfirst($group) }}
                            </td>
                        </tr>
                        @foreach ($permissions as $permission)
                            <tr class="border-t border-border">
                                <td class="px-4 py-2.5">
                                    <span class="font-mono text-xs">{{ $permission->slug }}</span>
                                </td>
                                @foreach ($roles as $role)
                                    @php $has = $role->slug === 'super_admin' || in_array($permission->slug, $roleSlugs[$role->id], true); @endphp
                                    <td class="px-4 py-2.5 text-center">
                                        @if ($has)
                                            <span class="inline-block w-2.5 h-2.5 rounded-full" style="background: var(--green);" title="granted"></span>
                                        @else
                                            <span class="inline-block w-2.5 h-2.5 rounded-full" style="background: color-mix(in srgb, var(--muted) 35%, transparent);" title="denied"></span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>
</x-app-layout>
