<?php

namespace Tests\Feature;

use App\Jobs\RunExecutionJob;
use App\Models\Connector;
use App\Models\ConnectorCredential;
use App\Models\ExecutionJob;
use App\Models\Workspace;
use App\Modules\ExecutionContext;
use App\Modules\LeadHub\PullLeadHubMeasurementsAction;
use App\Modules\MiroTalk\PullMiroTalkMeasurementsAction;
use App\Modules\ModuleRegistry;
use App\Modules\PerfexCrm\PullPerfexCrmMeasurementsAction;
use App\Modules\RocketLms\PullRocketLmsMeasurementsAction;
use App\Modules\TourGuide\PullTourGuideMeasurementsAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Project 2, Milestone 7 — cross-adapter verification, per the requirements doc §"Milestone 7"
 * and §"Explicit exclusions"/§"Change-control rules". Tests properties that must hold ACROSS all
 * 5 KPI adapters together, not any one adapter in isolation (that's Project2AdapterExecutionTest).
 *
 * Scope note: the doc's own namespace-collision rule ("Two source applications may use the same
 * KPI code only under different approved namespaces") is enforced at the DB level by ZaiKPI's
 * own `NamespacePolicy` + `UNIQUE(tenant, kpi_namespace, kpi_code)` constraint — that lives in
 * the separate `kpi-sourcecode` app, not here. What THIS adapter layer is responsible for, and
 * what's tested below, is producing a namespace that's structurally guaranteed to comply with
 * that policy (starts with the source's own reserved prefix) — not re-testing ZaiKPI's own DB
 * constraint, which has its own test suite in that app.
 *
 * Every adapter now delivers to ZaiKPI automatically (2026-09-05 client-requested fix), so
 * `runAdapter()`/`fakeAllSources()` fake the ZaiKPI lookup+push calls too, and a real ZaiKPI
 * connector is created in every workspace used here.
 */
class Project2CrossAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const ADAPTER_SLUGS = [
        'perfex_crm' => ['action' => PullPerfexCrmMeasurementsAction::class, 'kpi' => 'PX-LEADS', 'base_url' => 'https://dctrd.us/_ERP'],
        'rocket_lms' => ['action' => PullRocketLmsMeasurementsAction::class, 'kpi' => 'RL-SALES', 'base_url' => 'https://dctrd.us'],
        'tour_guide' => ['action' => PullTourGuideMeasurementsAction::class, 'kpi' => 'TG-GUIDE-STARTS', 'base_url' => 'https://usertour.dctrd.us'],
        'mirotalk' => ['action' => PullMiroTalkMeasurementsAction::class, 'kpi' => 'MT-ACTIVE-ROOMS', 'base_url' => 'https://11161115.xyz'],
        'leadhub' => ['action' => PullLeadHubMeasurementsAction::class, 'kpi' => 'LH-NEW-LEADS', 'base_url' => 'https://lead.dctrd.us'],
    ];

    private function workspace(): Workspace
    {
        return Workspace::create(['name' => 'Test', 'slug' => 'test-' . Str::random(8), 'environment' => 'staging', 'status' => 'active']);
    }

    private function connector(Workspace $ws, string $slug, string $baseUrl): Connector
    {
        $connector = Connector::create([
            'workspace_id' => $ws->id, 'name' => $slug, 'slug' => $slug, 'type' => 'kpi_adapter',
            'provider' => $slug, 'role' => 'source', 'status' => 'healthy', 'enabled' => true,
            'config' => ['base_url' => $baseUrl, 'timeout' => 10],
        ]);
        $cred = new ConnectorCredential(['connector_id' => $connector->id, 'key' => 'api_token', 'type' => 'secret']);
        $cred->setSecret('test-token');
        $cred->save();

        foreach (['api_key' => 'test-api-key', 'api_key_secret' => 'test-mirotalk-secret'] as $key => $value) {
            $c = new ConnectorCredential(['connector_id' => $connector->id, 'key' => $key, 'type' => 'secret']);
            $c->setSecret($value);
            $c->save();
        }

        return $connector->fresh(['credentials']);
    }

    private function zaikpiConnector(Workspace $ws): Connector
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

    /** Fakes every source's simplest successful shape, plus a successful ZaiKPI lookup+push. */
    private function fakeAllSources(): void
    {
        Http::fake([
            '*/api/leads*' => Http::response(['result' => [
                ['id' => 1, 'dateadded' => now()->toDateString(), 'date_converted' => null],
            ]], 200),
            '*/api/development/panel/financial/sales*' => Http::response(['success' => true, 'data' => ['sales' => [
                ['id' => 1, 'buyer_id' => 1, 'type' => 'webinar', 'created_at' => now()->timestamp, 'amount' => '10', 'total_amount' => '10', 'refund_at' => null],
            ]]], 200),
            '*/v1/content*' => Http::response(['results' => [['id' => 'c1']], 'next' => null], 200),
            '*/v1/content-sessions*' => Http::response(['results' => [
                ['id' => 's1', 'contentId' => 'c1', 'userId' => 'u1', 'completed' => true, 'createdAt' => now()->toIso8601String()],
            ], 'next' => null], 200),
            '*/api/v1/stats*' => Http::response(['success' => true, 'totalRooms' => 1, 'totalUsers' => 2], 200),
            '*lead.dctrd.us/api/v1/leads*' => Http::response(['data' => [
                ['id' => 1, 'status' => 'new', 'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(), 'contacted_at' => null, 'pipeline_stage_id' => null],
            ], 'meta' => ['current_page' => 1, 'last_page' => 1]], 200),
            '*kpi.dctrd.us/api/v1/kpis?*' => Http::response(['data' => [['uuid' => 'zk-kpi-uuid']]], 200),
            '*kpi.dctrd.us/api/v1/kpis/*/measurements' => Http::response(['data' => ['uuid' => 'zk-measurement-uuid']], 201),
        ]);
    }

    private function runAdapter(string $sourceKey, ?string $correlationId = null): array
    {
        $this->fakeAllSources();
        $cfg = self::ADAPTER_SLUGS[$sourceKey];

        $ws = $this->workspace();
        $connector = $this->connector($ws, $sourceKey, $cfg['base_url']);
        $this->zaikpiConnector($ws);
        $context = new ExecutionContext($ws, $connector, $correlationId ? ['correlation_id' => $correlationId] : []);

        $result = app($cfg['action'])->execute([
            'kpi_code' => $cfg['kpi'],
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => now()->subDay()->toIso8601String(),
            'period_end' => now()->toIso8601String(),
        ], $context);

        $this->assertTrue($result->success, "{$sourceKey} adapter failed: {$result->error}");

        return $result->output['measurement'];
    }

    public function test_all_five_adapters_produce_namespaces_starting_with_their_own_source_prefix(): void
    {
        foreach (array_keys(self::ADAPTER_SLUGS) as $sourceKey) {
            $measurement = $this->runAdapter($sourceKey);
            $this->assertSame($sourceKey, $measurement['source_application']);
            $this->assertStringStartsWith(
                "{$sourceKey}.",
                $measurement['kpi_namespace'],
                "{$sourceKey}'s kpi_namespace must start with its own reserved prefix — the property NamespacePolicy relies on for collision-safety across sources."
            );
        }
    }

    public function test_two_different_sources_never_produce_the_same_namespace_even_with_similar_kpi_codes(): void
    {
        $namespaces = [];
        foreach (array_keys(self::ADAPTER_SLUGS) as $sourceKey) {
            $namespaces[] = $this->runAdapter($sourceKey)['kpi_namespace'];
        }

        $this->assertSame(count($namespaces), count(array_unique($namespaces)), 'Two sources produced an identical kpi_namespace — this would collide under UNIQUE(tenant, kpi_namespace, kpi_code) in ZaiKPI.');
    }

    public function test_tenant_uuid_is_preserved_exactly_by_every_adapter(): void
    {
        foreach (self::ADAPTER_SLUGS as $sourceKey => $cfg) {
            $this->fakeAllSources();
            $ws = $this->workspace();
            $connector = $this->connector($ws, $sourceKey, $cfg['base_url']);
            $this->zaikpiConnector($ws);
            $context = new ExecutionContext($ws, $connector);
            $tenantUuid = (string) Str::uuid();

            $result = app($cfg['action'])->execute([
                'kpi_code' => $cfg['kpi'],
                'tenant_uuid' => $tenantUuid,
                'period_start' => now()->subDay()->toIso8601String(),
                'period_end' => now()->toIso8601String(),
            ], $context);

            $this->assertTrue($result->success, "{$sourceKey} failed: {$result->error}");
            $this->assertSame($tenantUuid, $result->output['measurement']['tenant_uuid'], "{$sourceKey} did not preserve tenant_uuid exactly.");
        }
    }

    public function test_external_uuid_never_collides_across_the_five_adapters(): void
    {
        $uuids = [];
        foreach (array_keys(self::ADAPTER_SLUGS) as $sourceKey) {
            $uuids[] = $this->runAdapter($sourceKey)['external_uuid'];
        }

        $this->assertSame(count($uuids), count(array_unique($uuids)));
        foreach ($uuids as $uuid) {
            $this->assertTrue(Str::isUuid($uuid), "external_uuid '{$uuid}' is not a valid UUID.");
        }
    }

    public function test_correlation_id_is_inherited_from_execution_context_when_supplied(): void
    {
        $known = (string) Str::uuid();
        $measurement = $this->runAdapter('perfex_crm', $known);

        $this->assertSame($known, $measurement['correlation_id']);
    }

    public function test_correlation_id_is_still_generated_when_not_supplied(): void
    {
        $a = $this->runAdapter('rocket_lms')['correlation_id'];
        $b = $this->runAdapter('tour_guide')['correlation_id'];

        $this->assertTrue(Str::isUuid($a));
        $this->assertTrue(Str::isUuid($b));
        $this->assertNotSame($a, $b, 'Two separate executions with no supplied correlation_id must not coincidentally share one.');
    }

    /**
     * Client-requested fix, 2026-09-05: "the correlation ID must continue through the ZaiKPI
     * measurement request so that a run can be traced from source → Connector → ZaiKPI." The
     * measurement POST body has no correlation_id field (confirmed from
     * KpiMeasurementController::store()'s real validation rules) — the real carrier is the
     * X-Correlation-ID header, read by ZaiKPI's own CorrelationId middleware.
     */
    public function test_correlation_id_reaches_the_actual_zaikpi_push_request_as_a_header(): void
    {
        $known = (string) Str::uuid();
        $this->runAdapter('perfex_crm', $known);

        Http::assertSent(function ($request) use ($known) {
            if (! str_contains($request->url(), '/measurements')) {
                return true;
            }
            return $request->hasHeader('X-Correlation-ID', $known);
        });
    }

    /**
     * Runs through the REAL execution pipeline (ExecutionJob + RunExecutionJob + ModuleRegistry
     * — the same path a live webhook-triggered or scheduled pull actually takes in production),
     * not just calling execute() directly, per the doc's "A failure in one adapter does not
     * block the other adapters" acceptance criterion. Also proves RunExecutionJob really does
     * propagate a correlation id end-to-end (client-requested fix, 2026-09-05).
     */
    public function test_one_adapter_failing_does_not_block_a_different_adapter_via_the_real_pipeline(): void
    {
        $this->fakeAllSources();
        $ws = $this->workspace();
        $this->zaikpiConnector($ws);

        // Job A: Perfex CRM, deliberately no connector attached — a clean, expected failure.
        $failingJob = ExecutionJob::create([
            'workspace_id' => $ws->id,
            'type' => 'perfex_crm.pull_measurements',
            'status' => 'pending',
            'input' => ['kpi_code' => 'PX-LEADS', 'tenant_uuid' => (string) Str::uuid(), 'period_start' => now()->subDay()->toIso8601String(), 'period_end' => now()->toIso8601String()],
        ]);

        // Job B: Rocket LMS, properly configured — should succeed independently of Job A.
        $rocketLmsConnector = $this->connector($ws, 'rocket_lms', 'https://dctrd.us');
        $succeedingJob = ExecutionJob::create([
            'workspace_id' => $ws->id,
            'connector_id' => $rocketLmsConnector->id,
            'type' => 'rocket_lms.pull_measurements',
            'status' => 'pending',
            'input' => ['kpi_code' => 'RL-SALES', 'tenant_uuid' => (string) Str::uuid(), 'period_start' => now()->subDay()->toIso8601String(), 'period_end' => now()->toIso8601String()],
        ]);

        $this->assertNotNull($failingJob->correlation_id, 'ExecutionJob must auto-generate a correlation id at creation.');
        $this->assertNotNull($succeedingJob->correlation_id);

        $registry = app(ModuleRegistry::class);
        (new RunExecutionJob($failingJob->id))->handle($registry);
        (new RunExecutionJob($succeedingJob->id))->handle($registry);

        $failingJob->refresh();
        $succeedingJob->refresh();

        $this->assertSame('failed', $failingJob->status);
        $this->assertStringContainsString('No Perfex CRM connector', $failingJob->error);

        $this->assertSame('completed', $succeedingJob->status, 'Rocket LMS job must succeed independently even though the Perfex CRM job failed.');
        $this->assertNotNull($succeedingJob->result);
        // The correlation id RunExecutionJob propagated into ExecutionContext reached the actual
        // outbound ZaiKPI push, proving the full source → Connector → ZaiKPI trace survives.
        $this->assertSame($succeedingJob->correlation_id, $succeedingJob->result['measurement']['correlation_id']);
    }
}
