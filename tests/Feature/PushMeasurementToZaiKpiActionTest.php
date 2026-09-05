<?php

namespace Tests\Feature;

use App\Models\Connector;
use App\Models\ConnectorCredential;
use App\Models\Workspace;
use App\Modules\ExecutionContext;
use App\Modules\ZaiKpi\PushMeasurementToZaiKpiAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The second half of Project 2's "source → Connector → ZaiKPI" delivery — see
 * PushMeasurementToZaiKpiAction's own docblock for why this is a separate module from the 5
 * Pull*MeasurementsAction adapters.
 */
class PushMeasurementToZaiKpiActionTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): Workspace
    {
        return Workspace::create(['name' => 'Test', 'slug' => 'test-' . Str::random(8), 'environment' => 'staging', 'status' => 'active']);
    }

    private function zaiKpiConnector(Workspace $ws): Connector
    {
        $connector = Connector::create([
            'workspace_id' => $ws->id, 'name' => 'zaikpi', 'slug' => 'zaikpi', 'type' => 'zaikpi',
            'provider' => 'zaikpi', 'role' => 'target', 'status' => 'healthy', 'enabled' => true,
            'config' => ['base_url' => 'https://kpi.dctrd.us', 'timeout' => 10],
        ]);
        $cred = new ConnectorCredential(['connector_id' => $connector->id, 'key' => 'api_token', 'type' => 'secret']);
        $cred->setSecret('test-zaikpi-token');
        $cred->save();

        return $connector->fresh(['credentials']);
    }

    private function measurement(array $overrides = []): array
    {
        return array_merge([
            'tenant_uuid' => (string) Str::uuid(),
            'source_application' => 'perfex_crm',
            'external_uuid' => (string) Str::uuid(),
            'kpi_namespace' => 'perfex_crm.crm',
            'kpi_code' => 'PX-LEADS',
            'kpi_domain' => 'crm',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'measured_at' => now()->toIso8601String(),
            'correlation_id' => (string) Str::uuid(),
            'value' => ['count' => 5],
            'primary_value' => 5.0,
        ], $overrides);
    }

    public function test_pushes_a_measurement_to_zaikpi_when_the_kpi_definition_exists(): void
    {
        Http::fake([
            '*/api/v1/kpis?*' => Http::response(['data' => [
                ['uuid' => 'zk-kpi-uuid-1', 'kpi_code' => 'PX-LEADS'],
            ]], 200),
            '*/api/v1/kpis/zk-kpi-uuid-1/measurements' => Http::response(['data' => [
                'uuid' => 'zk-measurement-uuid-1',
            ]], 201),
        ]);

        $ws = $this->workspace();
        $connector = $this->zaiKpiConnector($ws);
        $context = new ExecutionContext($ws, $connector);

        $result = (new PushMeasurementToZaiKpiAction())->execute([
            'measurement' => $this->measurement(),
        ], $context);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('zk-kpi-uuid-1', $result->output['zaikpi_kpi_uuid']);
        $this->assertSame('zk-measurement-uuid-1', $result->output['zaikpi_measurement_uuid']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/measurements')) {
                return true; // the lookup GET, not under test here
            }

            // No Idempotency-Key header on a measurement push (client-review fix, 2026-09-05):
            // ZaiKPI's separate Idempotency middleware does a stricter, body-hash-based conflict
            // check that rejects a genuine replay whenever `measured_at` is freshly generated —
            // `source_event_uuid` in the payload is ZaiKPI's own, correct replay guard for this
            // endpoint, so the header would only get in its way. See ZaiKpiClient::pushMeasurement().
            return $request->hasHeader('Authorization', 'Bearer test-zaikpi-token')
                && ! $request->hasHeader('Idempotency-Key')
                && $request['measured_value'] === 5.0
                && $request['source_event_uuid'] === $request['uuid'];
        });
    }

    public function test_fails_cleanly_when_the_kpi_is_not_yet_defined_in_zaikpi(): void
    {
        Http::fake([
            '*/api/v1/kpis?*' => Http::response(['data' => []], 200),
        ]);

        $ws = $this->workspace();
        $connector = $this->zaiKpiConnector($ws);
        $context = new ExecutionContext($ws, $connector);

        $result = (new PushMeasurementToZaiKpiAction())->execute([
            'measurement' => $this->measurement(),
        ], $context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No approved KPI definition found', $result->error);
    }

    public function test_fails_cleanly_when_primary_value_is_missing(): void
    {
        $ws = $this->workspace();
        $connector = $this->zaiKpiConnector($ws);
        $context = new ExecutionContext($ws, $connector);

        $result = (new PushMeasurementToZaiKpiAction())->execute([
            'measurement' => $this->measurement(['primary_value' => null]),
        ], $context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('primary_value is required', $result->error);
    }

    public function test_requires_a_connector(): void
    {
        $ws = $this->workspace();
        $context = new ExecutionContext($ws, null);

        $result = (new PushMeasurementToZaiKpiAction())->execute([
            'measurement' => $this->measurement(),
        ], $context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No ZaiKPI connector', $result->error);
    }
}
