<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleRegistryTest extends TestCase
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

    public function test_registry_syncs_code_modules_into_the_table(): void
    {
        $this->assertDatabaseHas('modules', ['slug' => 'businessapp.create_invoice', 'type' => 'action']);
        $this->assertDatabaseHas('modules', ['slug' => 'core.webhook_received', 'type' => 'trigger']);
    }

    public function test_registry_exposes_the_contract_for_a_slug(): void
    {
        $contract = app(ModuleRegistry::class)->find('businessapp.create_invoice');

        $this->assertNotNull($contract);
        $this->assertSame('Create Invoice', $contract->name());
        $this->assertContains('flows.execute', $contract->scopes());
    }

    public function test_sync_is_idempotent(): void
    {
        $before = Module::count();
        app(ModuleRegistry::class)->sync();
        $this->assertSame($before, Module::count());
    }

    public function test_ops_can_toggle_module_enabled_state(): void
    {
        $module = Module::where('slug', 'businessapp.create_invoice')->firstOrFail();
        $this->assertTrue($module->enabled);

        $this->actingAs($this->user('ops'))
            ->patch("/admin/modules/{$module->id}/toggle")
            ->assertRedirect();

        $this->assertFalse($module->fresh()->enabled);
    }

    public function test_analyst_cannot_toggle_modules(): void
    {
        $module = Module::first();
        $this->actingAs($this->user('analyst'))
            ->patch("/admin/modules/{$module->id}/toggle")
            ->assertForbidden();
    }

    public function test_analyst_can_view_module_registry(): void
    {
        $this->actingAs($this->user('analyst'))->get('/admin/modules')->assertOk();
    }

    public function test_manager_can_create_a_module(): void
    {
        $this->actingAs($this->user('admin'))->post('/admin/modules', [
            'name' => 'Send Slack Message',
            'slug' => 'slack.send_message',
            'type' => 'action',
            'execution_method' => 'queue',
            'version' => '1.0.0',
            'scopes' => 'flows.execute',
            'input_schema' => '{"channel":"string","text":"string"}',
            'enabled' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('modules', [
            'slug' => 'slack.send_message',
            'type' => 'action',
            // No code contract yet, so it is honestly marked unavailable.
            'health_status' => 'unavailable',
        ]);
    }

    public function test_manager_can_edit_a_module(): void
    {
        $module = Module::create(['name' => 'Temp', 'slug' => 'temp.mod', 'type' => 'action', 'execution_method' => 'queue', 'enabled' => true]);

        $this->actingAs($this->user('admin'))->put("/admin/modules/{$module->id}", [
            'name' => 'Renamed Module',
            'slug' => 'temp.mod',
            'type' => 'transform',
            'execution_method' => 'sync',
            'enabled' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('modules', ['id' => $module->id, 'name' => 'Renamed Module', 'type' => 'transform']);
    }

    public function test_create_and_edit_forms_render(): void
    {
        $module = Module::first();
        $this->actingAs($this->user('admin'))->get('/admin/modules/create')->assertOk()->assertSee('New Module');
        $this->actingAs($this->user('admin'))->get("/admin/modules/{$module->id}/edit")->assertOk()->assertSee('Edit Module');
    }

    public function test_code_backed_module_cannot_be_deleted(): void
    {
        $module = Module::where('slug', 'businessapp.create_invoice')->firstOrFail();
        $this->actingAs($this->user('admin'))->delete("/admin/modules/{$module->id}")->assertRedirect();
        $this->assertDatabaseHas('modules', ['id' => $module->id]);
    }
}
