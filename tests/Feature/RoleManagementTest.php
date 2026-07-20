<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    public function test_ops_admin_cannot_manage_roles(): void
    {
        $this->actingAs($this->user('ops'))->get('/admin/roles/create')->assertForbidden();

        $role = Role::where('slug', 'ops_admin')->first();
        $this->actingAs($this->user('ops'))
            ->put("/admin/roles/{$role->id}", ['name' => 'Hacked', 'permissions' => []])
            ->assertForbidden();
    }

    public function test_super_admin_can_edit_role_permissions_and_it_takes_effect(): void
    {
        $core = Workspace::where('slug', 'core-operations')->first();
        $opsRole = Role::where('slug', 'ops_admin')->first();

        // Baseline: ops_admin can write connectors.
        $this->assertTrue($this->user('ops')->hasPermissionTo('connectors.write', $core));

        // Super admin strips the role down to a single permission.
        $viewId = Permission::where('slug', 'connectors.view')->value('id');
        $this->actingAs($this->user('admin'))
            ->put("/admin/roles/{$opsRole->id}", ['name' => $opsRole->name, 'permissions' => [$viewId]])
            ->assertRedirect(route('admin.roles.index'));

        // The change takes effect immediately for members of that role.
        $this->assertFalse($this->user('ops')->hasPermissionTo('connectors.write', $core));
        $this->assertTrue($this->user('ops')->hasPermissionTo('connectors.view', $core));
    }

    public function test_super_admin_can_create_custom_role(): void
    {
        $this->actingAs($this->user('admin'))
            ->post('/admin/roles', ['name' => 'Support Agent', 'permissions' => []])
            ->assertRedirect(route('admin.roles.index'));

        $this->assertDatabaseHas('roles', ['name' => 'Support Agent', 'is_system' => false]);
    }

    public function test_system_role_cannot_be_deleted(): void
    {
        $role = Role::where('slug', 'reviewer')->first();

        $this->actingAs($this->user('admin'))->delete("/admin/roles/{$role->id}");

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }
}
