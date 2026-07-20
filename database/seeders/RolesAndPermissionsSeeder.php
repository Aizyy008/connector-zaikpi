<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Permissions (from the canonical catalog in App\Support\Permissions).
        foreach (Permissions::CATALOG as $group => $slugs) {
            foreach ($slugs as $slug) {
                Permission::updateOrCreate(
                    ['slug' => $slug],
                    ['group' => $group, 'name' => Permissions::label($slug)],
                );
            }
        }

        $allSlugs = Permissions::slugs();
        $viewSlugs = Permissions::viewSlugs();

        // 2. Roles
        $roles = [
            'super_admin' => ['Super Admin', 'Full access, governance, critical overrides'],
            'ops_admin' => ['Ops Admin', 'Daily operations, connectors, queue/logs, approvals'],
            'reviewer' => ['Reviewer', 'Approve/reject exceptions; review-only audit access'],
            'analyst' => ['Read-only Analyst', 'View dashboards, reports, and logs; no changes'],
        ];

        foreach ($roles as $slug => [$name, $desc]) {
            Role::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $desc, 'is_system' => true],
            );
        }

        // 3. Role → permission matrix (see docs/03-permissions-model.md).
        $opsExtra = [
            'connectors.write', 'connectors.test', 'credentials.manage', 'modules.manage',
            'capabilities.manage', 'mappings.manage', 'webhooks.manage', 'flows.manage',
            'flows.execute', 'queue.retry', 'approvals.decide',
        ];

        $matrix = [
            'super_admin' => $allSlugs,
            'ops_admin' => array_merge($viewSlugs, $opsExtra),
            'reviewer' => array_merge($viewSlugs, ['approvals.decide']),
            'analyst' => array_values(array_diff($viewSlugs, ['audit.view'])),
        ];

        foreach ($matrix as $roleSlug => $slugs) {
            $role = Role::where('slug', $roleSlug)->first();
            $ids = Permission::whereIn('slug', $slugs)->pluck('id');
            $role->permissions()->sync($ids);
        }
    }
}
