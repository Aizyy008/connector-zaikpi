<?php

namespace Tests\Feature;

use App\Models\WebhookEndpoint;
use App\Models\WebhookPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function endpoint(): WebhookEndpoint
    {
        return WebhookEndpoint::withoutWorkspaceScope()->where('slug', 'commerceapp-orders')->firstOrFail();
    }

    private function sign(WebhookEndpoint $endpoint, string $body): string
    {
        return hash_hmac($endpoint->signature_algo, $body, $endpoint->secret);
    }

    public function test_signed_valid_payload_is_accepted_and_logged(): void
    {
        $endpoint = $this->endpoint();
        $body = json_encode(['order_number' => 'A-1', 'customer' => ['email' => 'x@y.com'], 'currency' => 'usd', 'total' => 10]);

        $response = $this->call('POST', "/webhooks/{$endpoint->slug}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $this->sign($endpoint, $body),
        ], $body);

        $response->assertStatus(202)->assertJson(['status' => 'accepted']);

        $this->assertDatabaseHas('webhook_payloads', [
            'endpoint_id' => $endpoint->id,
            'workspace_id' => $endpoint->workspace_id,
            'connector_id' => $endpoint->connector_id,
            'status' => 'valid',
        ]);
    }

    public function test_invalid_json_is_stored_with_error_status(): void
    {
        $endpoint = $this->endpoint();
        $body = '{"broken": ';

        $response = $this->call('POST', "/webhooks/{$endpoint->slug}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $this->sign($endpoint, $body),
        ], $body);

        $response->assertStatus(422);
        $payload = WebhookPayload::withoutWorkspaceScope()->latest('id')->first();
        $this->assertSame('invalid', $payload->status);
        $this->assertNotNull($payload->error);
    }

    public function test_bad_signature_is_rejected_and_logged_invalid(): void
    {
        $endpoint = $this->endpoint();
        $body = json_encode(['order_number' => 'A-2']);

        $before = WebhookPayload::withoutWorkspaceScope()->count();

        $response = $this->call('POST', "/webhooks/{$endpoint->slug}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => 'deadbeef',
        ], $body);

        $response->assertStatus(401);
        $this->assertSame($before + 1, WebhookPayload::withoutWorkspaceScope()->count());
        $this->assertSame('invalid', WebhookPayload::withoutWorkspaceScope()->latest('id')->first()->status);
    }

    public function test_unknown_endpoint_returns_404(): void
    {
        $this->call('POST', '/webhooks/does-not-exist', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')
            ->assertStatus(404);
    }

    public function test_intake_does_not_require_csrf_token(): void
    {
        // No session/CSRF token supplied — external callers cannot provide one.
        $endpoint = $this->endpoint();
        $body = json_encode(['order_number' => 'A-3']);

        $this->call('POST', "/webhooks/{$endpoint->slug}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $this->sign($endpoint, $body),
        ], $body)->assertStatus(202);
    }
}
