<?php

namespace Tests\Feature;

use App\Models\Connector;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(); // DatabaseSeeder: roles, permissions, workspaces, users
    }

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    public function test_guest_is_redirected_from_admin(): void
    {
        $this->get('/admin/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_user_reaches_dashboard(): void
    {
        $this->actingAs($this->user('ops'))->get('/admin/dashboard')->assertOk();
    }

    public function test_analyst_cannot_create_users_on_backend(): void
    {
        // Analyst lacks users.manage — POST must be blocked even without the UI.
        $this->actingAs($this->user('analyst'))
            ->post('/admin/users', [
                'name' => 'Hacker', 'email' => 'x@example.com',
                'password' => 'password123', 'status' => 'active', 'role_id' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_ops_admin_cannot_manage_workspaces(): void
    {
        // workspaces.manage is super-admin only.
        $this->actingAs($this->user('ops'))->get('/admin/workspaces/create')->assertForbidden();
    }

    public function test_super_admin_can_manage_workspaces(): void
    {
        $this->actingAs($this->user('admin'))->get('/admin/workspaces/create')->assertOk();
    }

    public function test_analyst_can_view_but_not_manage_users(): void
    {
        $this->actingAs($this->user('analyst'))->get('/admin/users')->assertOk();
        $this->actingAs($this->user('analyst'))->get('/admin/users/create')->assertForbidden();
    }

    public function test_reviewer_cannot_switch_to_workspace_they_do_not_belong_to(): void
    {
        $staging = Workspace::where('slug', 'staging-sandbox')->first();

        $this->actingAs($this->user('reviewer'))
            ->post('/admin/workspace/switch', ['workspace_id' => $staging->id])
            ->assertForbidden();
    }

    public function test_connectors_are_scoped_to_the_current_workspace(): void
    {
        $core = Workspace::where('slug', 'core-operations')->first();
        $staging = Workspace::where('slug', 'staging-sandbox')->first();
        $context = app(WorkspaceContext::class);

        $context->set($core);
        Connector::create(['name' => 'Store', 'slug' => 'store', 'type' => 'ecommerce']);
        $this->assertTrue(Connector::where('slug', 'store')->exists(), 'visible in its own workspace');

        $context->set($staging);
        $this->assertFalse(Connector::where('slug', 'store')->exists(), 'hidden from another workspace');
        $this->assertSame(0, Connector::count(), 'no connectors leak into staging');

        $context->set(null);
        $this->assertTrue(Connector::withoutWorkspaceScope()->where('slug', 'store')->exists(), 'escape hatch sees all');
    }
}
