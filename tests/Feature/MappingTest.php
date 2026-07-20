<?php

namespace Tests\Feature;

use App\Models\Connector;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\WebhookPayload;
use App\Models\Workspace;
use App\Services\MappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MappingTest extends TestCase
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

    public function test_mapping_service_applies_transforms(): void
    {
        $commerce = Connector::withoutWorkspaceScope()->where('slug', 'commerceapp')->firstOrFail();

        $result = app(MappingService::class)->applyFor($commerce, 'orders', [
            'order_number' => 'APP-B-3911',
            'customer' => ['email' => 'Jane@Example.com'],
            'currency' => 'eur',
            'total' => 149.0,
        ]);

        $this->assertSame('APP-B-3911', $result['mapped']['external_order_id']);
        $this->assertSame('jane@example.com', $result['mapped']['customer_reference']); // lowercased
        $this->assertSame('EUR', $result['mapped']['currency']);                          // uppercased
        $this->assertSame(149.0, $result['mapped']['subtotal']);
    }

    public function test_mapping_service_reports_missing_source_fields(): void
    {
        $commerce = Connector::withoutWorkspaceScope()->where('slug', 'commerceapp')->firstOrFail();

        $result = app(MappingService::class)->applyFor($commerce, 'orders', ['order_number' => 'X']);

        $this->assertContains('customer.email', $result['missing']);
    }

    public function test_analyst_cannot_manage_mappings_but_ops_can(): void
    {
        $this->actingAs($this->user('analyst'))->get('/admin/mappings/create')->assertForbidden();
        $this->actingAs($this->user('ops'))->get('/admin/mappings/create')->assertOk();

        $this->actingAs($this->user('ops'))->post('/admin/mappings', [
            'entity' => 'customers', 'source_field' => 'email', 'target_field' => 'customer.email',
            'transform_type' => 'lowercase', 'status' => 'review',
        ])->assertRedirect();

        $this->assertDatabaseHas('field_mappings', ['entity' => 'customers', 'source_field' => 'email']);
    }

    public function test_payload_logs_are_scoped_to_workspace(): void
    {
        $payload = WebhookPayload::withoutWorkspaceScope()->firstOrFail(); // seeded in Core
        $staging = Workspace::where('slug', 'staging-sandbox')->firstOrFail();

        // Viewer in Core can inspect it.
        $this->actingAs($this->user('ops'))->get("/admin/payloads/{$payload->id}")->assertOk();

        // Same payload is not reachable from Staging.
        $this->actingAs($this->user('ops'))
            ->withSession(['current_workspace_id' => $staging->id])
            ->get("/admin/payloads/{$payload->id}")
            ->assertNotFound();
    }

    public function test_endpoint_secret_is_encrypted_and_hidden(): void
    {
        $endpoint = WebhookEndpoint::withoutWorkspaceScope()->firstOrFail();
        $raw = \DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('secret');

        $this->assertStringStartsWith('whsec_', $endpoint->secret); // decrypts
        $this->assertStringNotContainsString('whsec_', (string) $raw); // ciphertext at rest
        $this->assertArrayNotHasKey('secret', $endpoint->toArray());   // hidden
    }
}
