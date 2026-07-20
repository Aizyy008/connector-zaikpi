<?php

namespace Tests\Feature;

use App\Models\Connector;
use App\Models\ConnectorCredential;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectorTest extends TestCase
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

    private function core(): Workspace
    {
        return Workspace::where('slug', 'core-operations')->firstOrFail();
    }

    public function test_analyst_cannot_create_a_connector(): void
    {
        $this->actingAs($this->user('analyst'))
            ->post('/admin/connectors', ['name' => 'X', 'type' => 'other', 'role' => 'none'])
            ->assertForbidden();
    }

    public function test_ops_can_create_a_connector_in_current_workspace(): void
    {
        $this->actingAs($this->user('ops'))
            ->post('/admin/connectors', ['name' => 'MarketingApp', 'type' => 'marketing', 'role' => 'outbound', 'enabled' => '1'])
            ->assertRedirect();

        $this->assertDatabaseHas('connectors', [
            'name' => 'MarketingApp',
            'workspace_id' => $this->core()->id,
        ]);
    }

    public function test_credential_is_stored_encrypted_masked_and_never_plaintext(): void
    {
        $connector = Connector::where('slug', 'businessapp')->firstOrFail();
        $secret = 'super-secret-token-XYZ';

        $this->actingAs($this->user('ops'))
            ->post("/admin/connectors/{$connector->id}/credentials", [
                'key' => 'client_secret', 'type' => 'oauth', 'value' => $secret,
            ])->assertRedirect();

        // Not stored in plaintext.
        $this->assertDatabaseMissing('connector_credentials', ['value' => $secret]);

        $cred = ConnectorCredential::where('connector_id', $connector->id)->where('key', 'client_secret')->firstOrFail();
        $this->assertSame($secret, $cred->value);           // decrypts correctly
        $this->assertStringEndsWith('XYZ', $cred->masked()); // masked reveals only the last four
        $this->assertStringStartsWith('••••', $cred->masked());
        $this->assertArrayNotHasKey('value', $cred->toArray()); // hidden from serialization
    }

    public function test_credential_update_with_blank_value_keeps_existing_secret(): void
    {
        $connector = Connector::where('slug', 'businessapp')->firstOrFail();
        $cred = ConnectorCredential::where('connector_id', $connector->id)->firstOrFail();
        $original = $cred->value;

        $this->actingAs($this->user('ops'))
            ->put("/admin/connectors/{$connector->id}/credentials/{$cred->id}", [
                'type' => 'basic', 'value' => '',
            ])->assertRedirect();

        $cred->refresh();
        $this->assertSame($original, $cred->value, 'blank value keeps the secret');
        $this->assertSame('basic', $cred->type);
    }

    public function test_health_check_sets_status(): void
    {
        $withCred = Connector::where('slug', 'businessapp')->firstOrFail();
        $this->actingAs($this->user('ops'))->post("/admin/connectors/{$withCred->id}/test")->assertRedirect();
        $this->assertSame('healthy', $withCred->fresh()->status);

        // A connector with no credentials reports disconnected.
        $bare = Connector::create([
            'workspace_id' => $this->core()->id, 'name' => 'Bare', 'slug' => 'bare', 'type' => 'other', 'role' => 'none',
        ]);
        $this->actingAs($this->user('ops'))->post("/admin/connectors/{$bare->id}/test")->assertRedirect();
        $this->assertSame('disconnected', $bare->fresh()->status);
    }

    public function test_connectors_are_not_accessible_from_another_workspace(): void
    {
        $connector = Connector::where('slug', 'businessapp')->firstOrFail(); // in Core
        $staging = Workspace::where('slug', 'staging-sandbox')->firstOrFail();

        // ops belongs to staging too; viewing a Core connector while in Staging → 404.
        $this->actingAs($this->user('ops'))
            ->withSession(['current_workspace_id' => $staging->id])
            ->get("/admin/connectors/{$connector->id}")
            ->assertNotFound();
    }

    public function test_analyst_cannot_manage_credentials(): void
    {
        $connector = Connector::where('slug', 'businessapp')->firstOrFail();
        $this->actingAs($this->user('analyst'))
            ->post("/admin/connectors/{$connector->id}/credentials", ['key' => 'k', 'type' => 'custom', 'value' => 'v'])
            ->assertForbidden();
    }
}
