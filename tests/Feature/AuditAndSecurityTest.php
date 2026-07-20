<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Connector;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditAndSecurityTest extends TestCase
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

    public function test_audit_trail_is_visible_to_permitted_roles_and_records_actions(): void
    {
        // Reviewer has audit.view; analyst does not.
        $this->actingAs($this->user('reviewer'))->get('/admin/audit')->assertOk();
        $this->actingAs($this->user('analyst'))->get('/admin/audit')->assertForbidden();
    }

    public function test_sensitive_actions_write_audit_entries(): void
    {
        $before = AuditLog::where('action', 'user.login')->count();

        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'password']);

        $this->assertSame($before + 1, AuditLog::where('action', 'user.login')->count());
    }

    public function test_audit_changes_never_contain_secrets(): void
    {
        // Credential rotation is audited, but only a masked/boolean hint is stored.
        $connector = Connector::where('slug', 'businessapp')->firstOrFail();
        $cred = $connector->credentials()->first();

        $this->actingAs($this->user('ops'))->put("/admin/connectors/{$connector->id}/credentials/{$cred->id}", [
            'type' => 'bearer', 'value' => 'brand-new-secret-value',
        ])->assertRedirect();

        $entry = AuditLog::where('action', 'connector.credential.rotated')->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertStringNotContainsString('brand-new-secret-value', json_encode($entry->changes));
    }

    public function test_password_hash_is_never_written_to_the_audit_trail(): void
    {
        $target = $this->user('analyst');
        $core = Workspace::where('slug', 'core-operations')->firstOrFail();
        $roleId = $target->workspaces()->where('workspaces.id', $core->id)->first()->pivot->role_id;

        $this->actingAs($this->user('admin'))->put("/admin/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'status' => 'active',
            'password' => 'a-brand-new-password',
            'memberships' => [['workspace_id' => $core->id, 'role_id' => $roleId]],
        ])->assertRedirect();

        $entry = AuditLog::where('action', 'user.updated')->latest('id')->first();
        $this->assertNotNull($entry);

        $encoded = json_encode($entry->changes);
        $this->assertStringNotContainsString($target->fresh()->password, $encoded); // no bcrypt hash
        $this->assertStringNotContainsString('$2y$', $encoded);                     // no bcrypt prefix
        $this->assertArrayHasKey('password_changed', $entry->changes ?? []);        // but the fact is recorded
    }

    public function test_audit_sanitize_command_redacts_legacy_secrets(): void
    {
        // Simulate a pre-fix row written directly (bypassing the write-time scrub).
        DB::table('audit_logs')->insert([
            'action' => 'user.updated',
            'changes' => json_encode([
                'name' => 'Old Name',
                'password' => '$2y$12$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ0123',
                'nested' => ['token' => 'sk_live_deadbeef'],
            ]),
            'created_at' => now(),
        ]);

        $this->artisan('audit:sanitize')->assertExitCode(0);

        $rows = AuditLog::whereNotNull('changes')->get();
        foreach ($rows as $row) {
            $json = json_encode($row->changes);
            $this->assertStringNotContainsString('$2y$', $json);
            $this->assertStringNotContainsString('sk_live_', $json);
        }
    }

    public function test_timestamps_display_in_the_configured_timezone(): void
    {
        config(['app.display_timezone' => 'Europe/Athens']);
        $utc = Carbon::parse('2026-07-14 13:40:00', 'UTC');

        $html = Blade::render('<x-datetime :value="$v" />', ['v' => $utc]);

        // 13:40 UTC is 16:40 in Athens (EEST, +3) in July.
        $this->assertStringContainsString('16:40:00', $html);
        $this->assertStringContainsString('EEST', $html);
    }

    public function test_cannot_attach_a_connector_from_another_workspace(): void
    {
        // Seeded connectors live in Core. Acting in Staging, referencing a Core
        // connector id must fail validation (workspace-scoped exists rule).
        $coreConnector = Connector::where('slug', 'commerceapp')->firstOrFail();
        $staging = Workspace::where('slug', 'staging-sandbox')->firstOrFail();

        $this->actingAs($this->user('ops'))
            ->withSession(['current_workspace_id' => $staging->id])
            ->post('/admin/mappings', [
                'connector_id' => $coreConnector->id,
                'entity' => 'orders', 'source_field' => 'x', 'target_field' => 'y',
                'transform_type' => 'none', 'status' => 'review',
            ])
            ->assertSessionHasErrors('connector_id');
    }

    public function test_full_flow_end_to_end_from_webhook_to_audit(): void
    {
        $endpoint = WebhookEndpoint::withoutWorkspaceScope()->where('slug', 'commerceapp-orders')->firstOrFail();
        $body = json_encode(['order_number' => 'FULL-1', 'customer' => ['email' => 'f@x.com'], 'currency' => 'eur', 'total' => 75]);
        $sig = hash_hmac('sha256', $body, $endpoint->secret);

        // Intake -> payload logged -> flow -> queued job (sync) -> completed.
        $res = $this->call('POST', "/webhooks/{$endpoint->slug}", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => $sig,
        ], $body);

        $res->assertStatus(202);
        $payloadId = $res->json('id');

        $this->assertDatabaseHas('webhook_payloads', ['id' => $payloadId, 'status' => 'processed']);
        $this->assertDatabaseHas('execution_jobs', ['payload_id' => $payloadId, 'status' => 'completed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'webhook.received']);
    }
}
