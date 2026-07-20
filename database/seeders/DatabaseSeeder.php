<?php

namespace Database\Seeders;

use App\Jobs\RunExecutionJob;
use App\Models\AuditLog;
use App\Models\Connector;
use App\Models\ConnectorCredential;
use App\Models\ExecutionJob;
use App\Models\FieldMapping;
use App\Models\Flow;
use App\Models\Role;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\WebhookPayload;
use App\Models\Workspace;
use App\Modules\ModuleRegistry;
use App\Services\ConnectorTester;
use App\Services\MappingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed demo data. Idempotent — safe to re-run.
     *
     * Passwords here are local/demo only. Real credentials ship in the private
     * handover note (see docs/06).
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // Workspaces
        $core = Workspace::updateOrCreate(
            ['slug' => 'core-operations'],
            ['name' => 'Core Operations', 'environment' => 'production', 'status' => 'active'],
        );

        $staging = Workspace::updateOrCreate(
            ['slug' => 'staging-sandbox'],
            ['name' => 'Staging Sandbox', 'environment' => 'staging', 'status' => 'active'],
        );

        // Users. is_super_admin bypasses workspace scope (owner account).
        $users = [
            ['name' => 'Owner Admin',    'email' => 'admin@example.com',     'username' => 'admin',    'is_super_admin' => true],
            ['name' => 'Ops Manager',    'email' => 'ops@example.com',       'username' => 'ops',      'is_super_admin' => false],
            ['name' => 'Review Desk',    'email' => 'reviewer@example.com',  'username' => 'reviewer', 'is_super_admin' => false],
            ['name' => 'Analyst',        'email' => 'analyst@example.com',   'username' => 'analyst',  'is_super_admin' => false],
            ['name' => 'Cross-Workspace', 'email' => 'multi@example.com',    'username' => 'multi',    'is_super_admin' => false],
        ];

        $models = [];
        foreach ($users as $data) {
            $models[$data['username']] = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'username' => $data['username'],
                    'password' => Hash::make('password'),
                    'is_super_admin' => $data['is_super_admin'],
                    'status' => 'active',
                ],
            );
        }

        // Memberships (role scoped per workspace). Super admin needs no membership
        // (it can access every workspace). Reviewer/analyst live only in Core to
        // demonstrate workspace isolation. The "Cross-Workspace" user belongs to
        // BOTH workspaces with a DIFFERENT role in each — demonstrating that a
        // single user can carry per-workspace roles.
        $roleId = fn (string $slug) => Role::where('slug', $slug)->value('id');

        $core->users()->syncWithoutDetaching([
            $models['ops']->id => ['role_id' => $roleId('ops_admin')],
            $models['reviewer']->id => ['role_id' => $roleId('reviewer')],
            $models['analyst']->id => ['role_id' => $roleId('analyst')],
            $models['multi']->id => ['role_id' => $roleId('ops_admin')],
        ]);

        $staging->users()->syncWithoutDetaching([
            $models['ops']->id => ['role_id' => $roleId('ops_admin')],
            $models['multi']->id => ['role_id' => $roleId('reviewer')],
        ]);

        // Demo connectors (in Core Operations) with encrypted credentials.
        $demoConnectors = [
            ['name' => 'CommerceApp', 'slug' => 'commerceapp', 'type' => 'ecommerce', 'provider' => 'Shopify', 'role' => 'secondary_source'],
            ['name' => 'BusinessApp', 'slug' => 'businessapp', 'type' => 'business_system', 'provider' => 'QuickBooks', 'role' => 'action_system'],
        ];

        $tester = app(ConnectorTester::class);
        $connectors = [];

        foreach ($demoConnectors as $data) {
            $connector = Connector::updateOrCreate(
                ['workspace_id' => $core->id, 'slug' => $data['slug']],
                ['name' => $data['name'], 'type' => $data['type'], 'provider' => $data['provider'], 'role' => $data['role'], 'enabled' => true],
            );

            $cred = ConnectorCredential::firstOrNew(['connector_id' => $connector->id, 'key' => 'api_token']);
            $cred->type = 'bearer';
            if (! $cred->exists) {
                $cred->setSecret('demo-'.Str::random(24));
            }
            $cred->save();

            $tester->test($connector); // sets health status
            $connectors[$data['slug']] = $connector;
        }

        // Sync code-defined modules into the registry.
        app(ModuleRegistry::class)->sync();

        $this->seedWebhooks($core, $connectors['commerceapp']);
        $this->seedFlows($core, $connectors['commerceapp']);
        $this->seedAuditTrail($core, $models['admin']);
    }

    private function seedAuditTrail(Workspace $core, User $admin): void
    {
        if (AuditLog::where('workspace_id', $core->id)->exists()) {
            return;
        }

        $entries = [
            ['action' => 'user.login', 'changes' => null, 'min' => 30],
            ['action' => 'connector.created', 'changes' => ['name' => 'BusinessApp'], 'min' => 25],
            ['action' => 'connector.credential.rotated', 'changes' => ['key' => 'api_token', 'value_changed' => true], 'min' => 20],
            ['action' => 'webhook.received', 'changes' => ['status' => 'valid'], 'min' => 6],
            ['action' => 'execution.retried', 'changes' => ['attempt' => 2], 'min' => 2],
        ];

        foreach ($entries as $e) {
            AuditLog::create([
                'workspace_id' => $core->id,
                'user_id' => $admin->id,
                'action' => $e['action'],
                'changes' => $e['changes'],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Seeder',
                'created_at' => now()->subMinutes($e['min']),
            ]);
        }
    }

    private function seedWebhooks(Workspace $core, Connector $commerce): void
    {
        // Inbound endpoint for CommerceApp orders (HMAC-signed).
        $endpoint = WebhookEndpoint::firstOrNew(['workspace_id' => $core->id, 'name' => 'CommerceApp Orders']);
        if (! $endpoint->exists) {
            $endpoint->fill([
                'connector_id' => $commerce->id,
                'slug' => 'commerceapp-orders',
                'entity' => 'orders',
                'secret' => 'whsec_'.Str::random(32),
                'enabled' => true,
            ])->save();
        }

        // Field mappings: incoming order payload -> canonical order fields.
        $mappings = [
            ['source_field' => 'order_number', 'target_field' => 'external_order_id', 'transform' => null, 'status' => 'validated'],
            ['source_field' => 'customer.email', 'target_field' => 'customer_reference', 'transform' => ['type' => 'lowercase'], 'status' => 'validated'],
            ['source_field' => 'currency', 'target_field' => 'currency', 'transform' => ['type' => 'uppercase'], 'status' => 'validated'],
            ['source_field' => 'total', 'target_field' => 'subtotal', 'transform' => null, 'status' => 'review'],
        ];

        foreach ($mappings as $m) {
            FieldMapping::updateOrCreate(
                ['workspace_id' => $core->id, 'connector_id' => $commerce->id, 'entity' => 'orders', 'source_field' => $m['source_field']],
                ['target_field' => $m['target_field'], 'transform' => $m['transform'], 'status' => $m['status']],
            );
        }

        // Demo payloads: one valid, one invalid — only seed once.
        if (WebhookPayload::withoutWorkspaceScope()->where('endpoint_id', $endpoint->id)->exists()) {
            return;
        }

        $valid = ['order_number' => 'APP-B-3911', 'customer' => ['email' => 'Jane@Example.com'], 'currency' => 'eur', 'total' => 149.00];
        WebhookPayload::create([
            'workspace_id' => $core->id,
            'connector_id' => $commerce->id,
            'endpoint_id' => $endpoint->id,
            'headers' => ['content-type' => 'application/json'],
            'raw_payload' => json_encode($valid),
            'parsed_payload' => $valid,
            'status' => 'valid',
            'received_at' => now()->subMinutes(6),
        ]);

        WebhookPayload::create([
            'workspace_id' => $core->id,
            'connector_id' => $commerce->id,
            'endpoint_id' => $endpoint->id,
            'headers' => ['content-type' => 'application/json'],
            'raw_payload' => '{"order_number": "APP-B-3912", "currency":',
            'parsed_payload' => null,
            'status' => 'invalid',
            'error' => 'Malformed or non-object JSON body.',
            'received_at' => now()->subMinutes(3),
        ]);
    }

    private function seedFlows(Workspace $core, Connector $commerce): void
    {
        $flow = Flow::updateOrCreate(
            ['workspace_id' => $core->id, 'slug' => 'orders-to-invoice'],
            [
                'name' => 'Paid order → Create invoice',
                'status' => 'active',
                'definition' => [
                    'trigger' => ['connector_id' => $commerce->id, 'entity' => 'orders'],
                    'action' => ['module' => 'businessapp.create_invoice'],
                ],
            ],
        );

        if (ExecutionJob::withoutWorkspaceScope()->where('flow_id', $flow->id)->exists()) {
            return;
        }

        $registry = app(ModuleRegistry::class);
        $payload = WebhookPayload::withoutWorkspaceScope()
            ->where('workspace_id', $core->id)->where('status', 'valid')->first();
        $mapped = app(MappingService::class)->applyFor($commerce, 'orders', $payload?->parsed_payload ?? []);

        // Completed example — run inline so the demo shows a finished job.
        $ok = ExecutionJob::create([
            'workspace_id' => $core->id, 'flow_id' => $flow->id, 'payload_id' => $payload?->id,
            'connector_id' => $commerce->id, 'type' => 'businessapp.create_invoice',
            'status' => 'pending', 'input' => $mapped['mapped'],
        ]);
        (new RunExecutionJob($ok->id))->handle($registry);

        // Failed example — unregistered module slug, retryable from the admin panel.
        $bad = ExecutionJob::create([
            'workspace_id' => $core->id, 'flow_id' => $flow->id,
            'connector_id' => $commerce->id, 'type' => 'missing.module',
            'status' => 'pending', 'input' => [],
        ]);
        (new RunExecutionJob($bad->id))->handle($registry);
    }
}
