@php
    $editing = $user->exists;
    $selClass = 'w-full rounded-xl border border-border bg-panel-2 px-3.5 py-2.5 text-text outline-none focus:border-blue focus:ring-2 focus:ring-blue/30';
    $labelClass = 'mb-2 block text-xs font-bold uppercase tracking-wide text-muted';
    $wsOptions = $workspaces->pluck('name', 'id');
    $roleOptions = $roles->pluck('name', 'id');
    // Rows to render: old input on validation error, else existing memberships,
    // else one empty row pre-filled with the current workspace for convenience.
    $rows = old('memberships', ! empty($memberships)
        ? $memberships
        : [['workspace_id' => $currentWorkspace->id, 'role_id' => null]]);
@endphp
<x-app-layout :title="$editing ? 'Edit User' : 'New User'" active="users">
    <x-slot:breadcrumb>Settings / Users / {{ $editing ? 'Edit' : 'Create' }}</x-slot:breadcrumb>
    <x-slot:subtitle>Assign this user to one or more workspaces, each with a role.</x-slot:subtitle>

    <x-card>
        <form method="POST"
              action="{{ $editing ? route('admin.users.update', $user) : route('admin.users.store') }}"
              class="space-y-5">
            @csrf
            @if ($editing) @method('PUT') @endif

            <div class="grid sm:grid-cols-2 gap-5">
                <x-input label="Full name" name="name" :value="$user->name" required placeholder="Jane Example" />
                <x-input label="Email" name="email" type="email" :value="$user->email" required placeholder="jane@example.com" />
                <x-input label="Username" name="username" :value="$user->username" placeholder="optional" />
                <x-select label="Status" name="status" :selected="$user->status" required
                          :options="['active' => 'Active', 'invited' => 'Invited', 'disabled' => 'Disabled']" />
            </div>

            <x-input label="{{ $editing ? 'New password (leave blank to keep)' : 'Password' }}"
                     name="password" type="password" :required="! $editing" autocomplete="new-password"
                     hint="Minimum 8 characters." />

            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="block text-xs font-bold uppercase tracking-wide text-muted">Workspace memberships</label>
                    <button type="button" id="add-membership" class="text-sm font-bold text-blue">+ Add workspace</button>
                </div>

                <div id="memberships" class="space-y-3">
                    @foreach ($rows as $i => $row)
                        <div class="membership-row rounded-xl border border-border bg-white/[.02] p-4">
                            <div class="mb-3 flex items-center justify-between">
                                <span class="text-xs font-bold uppercase tracking-wide text-muted">Membership</span>
                                <button type="button" class="remove-membership rounded-lg border border-border bg-panel px-3 py-1.5 text-sm font-bold">Remove</button>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="{{ $labelClass }}">Workspace</label>
                                    <select name="memberships[{{ $i }}][workspace_id]" required class="{{ $selClass }}">
                                        <option value="">Select workspace…</option>
                                        @foreach ($wsOptions as $id => $name)
                                            <option value="{{ $id }}" @selected((string) ($row['workspace_id'] ?? '') === (string) $id)>{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="{{ $labelClass }}">Role</label>
                                    <select name="memberships[{{ $i }}][role_id]" required class="{{ $selClass }}">
                                        <option value="">Select role…</option>
                                        @foreach ($roleOptions as $id => $name)
                                            <option value="{{ $id }}" @selected((string) ($row['role_id'] ?? '') === (string) $id)>{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @error('memberships') <p class="mt-1.5 text-sm" style="color: var(--red);">{{ $message }}</p> @enderror
                @error('memberships.*.workspace_id') <p class="mt-1.5 text-sm" style="color: var(--red);">{{ $message }}</p> @enderror
                @error('memberships.*.role_id') <p class="mt-1.5 text-sm" style="color: var(--red);">{{ $message }}</p> @enderror
            </div>

            <template id="membership-template">
                <div class="membership-row rounded-xl border border-border bg-white/[.02] p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wide text-muted">Membership</span>
                        <button type="button" class="remove-membership rounded-lg border border-border bg-panel px-3 py-1.5 text-sm font-bold">Remove</button>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="{{ $labelClass }}">Workspace</label>
                            <select name="memberships[__i__][workspace_id]" required class="{{ $selClass }}">
                                <option value="">Select workspace…</option>
                                @foreach ($wsOptions as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="{{ $labelClass }}">Role</label>
                            <select name="memberships[__i__][role_id]" required class="{{ $selClass }}">
                                <option value="">Select role…</option>
                                @foreach ($roleOptions as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </template>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="rounded-xl px-5 py-2.5 font-extrabold text-white shadow-lg"
                        style="background: linear-gradient(135deg, var(--blue), var(--purple));">
                    {{ $editing ? 'Save Changes' : 'Create User' }}
                </button>
                <a href="{{ route('admin.users.index') }}" class="rounded-xl border border-border bg-panel px-5 py-2.5 font-bold">Cancel</a>
            </div>
        </form>
    </x-card>

    <script>
        (function () {
            const list = document.getElementById('memberships');
            const template = document.getElementById('membership-template').innerHTML;
            let index = {{ count($rows) }};

            document.getElementById('add-membership').addEventListener('click', function () {
                list.insertAdjacentHTML('beforeend', template.replace(/__i__/g, index++));
            });

            list.addEventListener('click', function (event) {
                if (! event.target.classList.contains('remove-membership')) {
                    return;
                }
                // Always keep at least one membership row.
                if (list.querySelectorAll('.membership-row').length > 1) {
                    event.target.closest('.membership-row').remove();
                }
            });
        })();
    </script>
</x-app-layout>
