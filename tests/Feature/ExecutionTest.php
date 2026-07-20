<?php

namespace Tests\Feature;

use App\Jobs\RunExecutionJob;
use App\Models\Connector;
use App\Models\ExecutionJob;
use App\Models\Module;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(); // includes an active flow: CommerceApp orders -> create_invoice
    }

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    private function core(): Workspace
    {
        return Workspace::where('slug', 'core-operations')->firstOrFail();
    }

    public function test_webhook_payload_generates_and_completes_an_execution_job(): void
    {
        // QUEUE_CONNECTION=sync in tests, so dispatch runs the job inline.
        $endpoint = WebhookEndpoint::withoutWorkspaceScope()->where('slug', 'commerceapp-orders')->firstOrFail();
        $body = json_encode(['order_number' => 'E2E-1', 'customer' => ['email' => 'e2e@example.com'], 'currency' => 'usd', 'total' => 20]);
        $sig = hash_hmac('sha256', $body, $endpoint->secret);

        $response = $this->call('POST', "/webhooks/{$endpoint->slug}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $sig,
        ], $body);

        $response->assertStatus(202);
        $payloadId = $response->json('id');

        $this->assertDatabaseHas('execution_jobs', [
            'payload_id' => $payloadId,
            'type' => 'businessapp.create_invoice',
            'status' => 'completed',
        ]);
    }

    public function test_unregistered_module_marks_job_failed_with_error(): void
    {
        $job = ExecutionJob::create([
            'workspace_id' => $this->core()->id,
            'type' => 'missing.module',
            'status' => 'pending',
            'input' => [],
        ]);

        (new RunExecutionJob($job->id))->handle(app(ModuleRegistry::class));

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('not registered', $job->error);
    }

    public function test_disabled_module_cannot_be_executed(): void
    {
        Module::where('slug', 'businessapp.create_invoice')->update(['enabled' => false]);

        $job = ExecutionJob::create([
            'workspace_id' => $this->core()->id,
            'type' => 'businessapp.create_invoice',
            'status' => 'pending',
            'input' => ['external_order_id' => 'X', 'customer_reference' => 'y', 'currency' => 'USD', 'subtotal' => 1],
        ]);

        (new RunExecutionJob($job->id))->handle(app(ModuleRegistry::class));

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('disabled', $job->error);
    }

    public function test_incomplete_payload_is_not_processed_as_success(): void
    {
        $endpoint = WebhookEndpoint::withoutWorkspaceScope()->where('slug', 'commerceapp-orders')->firstOrFail();
        // Missing customer.email, currency and total — required by the mapping + module schema.
        $body = json_encode(['order_number' => 'INC-1']);
        $sig = hash_hmac('sha256', $body, $endpoint->secret);

        $response = $this->call('POST', "/webhooks/{$endpoint->slug}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $sig,
        ], $body);

        $response->assertStatus(202);
        $payloadId = $response->json('id');

        // No successful job, a failed job with an error, and the payload marked failed.
        $this->assertDatabaseMissing('execution_jobs', ['payload_id' => $payloadId, 'status' => 'completed']);
        $this->assertDatabaseHas('execution_jobs', ['payload_id' => $payloadId, 'status' => 'failed']);
        $this->assertDatabaseHas('webhook_payloads', ['id' => $payloadId, 'status' => 'failed']);
    }

    public function test_failed_job_can_be_retried_from_admin(): void
    {
        $connector = Connector::where('slug', 'commerceapp')->firstOrFail();
        $job = ExecutionJob::create([
            'workspace_id' => $this->core()->id,
            'connector_id' => $connector->id,
            'type' => 'businessapp.create_invoice',
            'status' => 'failed',
            'error' => 'previous failure',
            // Complete input (all required schema fields present) so the retry can succeed.
            'input' => ['external_order_id' => 'R-1', 'customer_reference' => 'c@x.com', 'currency' => 'USD', 'subtotal' => 10],
            'attempts' => 1,
        ]);

        $this->actingAs($this->user('ops'))
            ->post("/admin/queue/{$job->id}/retry")
            ->assertRedirect();

        $job->refresh();
        $this->assertSame('completed', $job->status); // reran inline and succeeded
        $this->assertSame(2, $job->attempts);
    }

    public function test_retry_of_incomplete_job_stays_failed(): void
    {
        // Reproduces the client scenario: a job that failed for missing required
        // fields must NOT complete on retry if the data is still incomplete.
        $connector = Connector::where('slug', 'commerceapp')->firstOrFail();
        $job = ExecutionJob::create([
            'workspace_id' => $this->core()->id,
            'connector_id' => $connector->id,
            'type' => 'businessapp.create_invoice',
            'status' => 'failed',
            'error' => 'Incomplete payload — missing source field(s): customer.email, currency, total.',
            'input' => ['external_order_id' => 'R-2'], // still missing customer_reference, currency, subtotal
            'attempts' => 1,
        ]);

        $this->actingAs($this->user('ops'))
            ->post("/admin/queue/{$job->id}/retry")
            ->assertRedirect();

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('missing required field', $job->error);
        $this->assertSame(2, $job->attempts);
    }

    public function test_analyst_cannot_retry_jobs(): void
    {
        $job = ExecutionJob::create([
            'workspace_id' => $this->core()->id, 'type' => 'x', 'status' => 'failed',
        ]);

        $this->actingAs($this->user('analyst'))
            ->post("/admin/queue/{$job->id}/retry")
            ->assertForbidden();
    }

    public function test_analyst_can_view_queue_but_not_manage_flows(): void
    {
        $this->actingAs($this->user('analyst'))->get('/admin/queue')->assertOk();
        $this->actingAs($this->user('analyst'))->get('/admin/flows/create')->assertForbidden();
    }

    public function test_flows_and_queue_index_pages_render(): void
    {
        $this->actingAs($this->user('ops'))->get('/admin/flows')->assertOk();
        $this->actingAs($this->user('ops'))->get('/admin/queue')->assertOk();
    }

    public function test_ops_can_create_a_flow(): void
    {
        $connector = Connector::where('slug', 'businessapp')->firstOrFail();

        $this->actingAs($this->user('ops'))->post('/admin/flows', [
            'name' => 'Invoice paid → tag',
            'connector_id' => $connector->id,
            'entity' => 'invoices',
            'module' => 'businessapp.create_invoice',
            'status' => 'active',
        ])->assertRedirect();

        $this->assertDatabaseHas('flows', ['name' => 'Invoice paid → tag', 'workspace_id' => $this->core()->id]);
    }
}
