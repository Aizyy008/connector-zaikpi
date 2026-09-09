<?php

namespace Tests\Feature;

use App\Models\Connector;
use App\Models\ConnectorCredential;
use App\Models\Workspace;
use App\Modules\ExecutionContext;
use App\Modules\LeadHub\PullLeadHubMeasurementsAction;
use App\Modules\MiroTalk\PullMiroTalkMeasurementsAction;
use App\Modules\PerfexCrm\PullPerfexCrmMeasurementsAction;
use App\Modules\RocketLms\PullRocketLmsMeasurementsAction;
use App\Modules\TourGuide\PullTourGuideMeasurementsAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Project 2 KPI adapters — Perfex CRM, Rocket LMS, Tour Guide, MiroTalk and LeadHub Pull
 * actions. Mirrors ExecutionTest.php's conventions (Http::fake() against real, confirmed
 * response shapes — see project_2_v1_files/docs/0{1,2,3,4,5,9}-*.md for how each shape was
 * verified).
 *
 * Every Pull action now delivers to ZaiKPI automatically in the same execution (client-requested
 * fix, 2026-09-05 review), so every success-path test here fakes BOTH the source call and the
 * ZaiKPI lookup/push calls via withZaiKpiSuccess(), and a real ZaiKPI connector is created
 * alongside the source connector via zaikpiConnector().
 */
class Project2AdapterExecutionTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): Workspace
    {
        return Workspace::create(['name' => 'Test', 'slug' => 'test-' . Str::random(8), 'environment' => 'staging', 'status' => 'active']);
    }

    /**
     * `$connectorSlug` lets a test create more than one Connector for the same provider in one
     * workspace (e.g. two Rocket LMS vendor connectors) — `connectors` has a unique constraint on
     * (workspace_id, slug), so they can't all share the provider name as their own slug. Defaults
     * to `$slug` (unchanged behavior for every existing call site, which only ever needs one).
     *
     * A Rocket LMS connector defaults to `vendor_user_id => 1` unless `$config` overrides it —
     * `PullRocketLmsMeasurementsAction` fails closed without one (client-flagged fix, 2026-09-09
     * 3rd review), and every pre-existing Rocket LMS test here predates that requirement, so this
     * default keeps them all passing without touching each test body individually. Pass an
     * explicit `vendor_user_id` (or `null`, to test the fail-closed behavior itself) via `$config`
     * to override it.
     */
    private function connector(Workspace $ws, string $slug, string $baseUrl, array $config = [], ?string $connectorSlug = null): Connector
    {
        $uniqueSlug = $connectorSlug ?? $slug;
        $mergedConfig = ['base_url' => $baseUrl, 'timeout' => 10] + $config;
        if ($slug === 'rocket_lms' && ! array_key_exists('vendor_user_id', $mergedConfig)) {
            $mergedConfig['vendor_user_id'] = 1;
        }
        $connector = Connector::create([
            'workspace_id' => $ws->id, 'name' => $uniqueSlug, 'slug' => $uniqueSlug, 'type' => 'kpi_adapter',
            'provider' => $slug, 'role' => 'source', 'status' => 'healthy', 'enabled' => true,
            'config' => $mergedConfig,
        ]);
        $cred = new ConnectorCredential(['connector_id' => $connector->id, 'key' => 'api_token', 'type' => 'secret']);
        $cred->setSecret('test-token');
        $cred->save();

        if ($slug === 'rocket_lms') {
            $apiKeyCred = new ConnectorCredential(['connector_id' => $connector->id, 'key' => 'api_key', 'type' => 'secret']);
            $apiKeyCred->setSecret('test-api-key');
            $apiKeyCred->save();
        }

        if ($slug === 'mirotalk') {
            $secretCred = new ConnectorCredential(['connector_id' => $connector->id, 'key' => 'api_key_secret', 'type' => 'secret']);
            $secretCred->setSecret('test-mirotalk-secret');
            $secretCred->save();
        }

        if ($slug === 'leadhub') {
            $leadHubCred = new ConnectorCredential(['connector_id' => $connector->id, 'key' => 'api_key', 'type' => 'secret']);
            $leadHubCred->setSecret('lh_test-key');
            $leadHubCred->save();
        }

        return $connector->fresh(['credentials']);
    }

    /** The ZaiKPI connector every Pull action's automatic delivery looks up by workspace+provider. */
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

    /** Merges a successful ZaiKPI lookup+push fake alongside a source's own Http::fake() patterns. */
    private function withZaiKpiSuccess(array $sourceFakes, string $measurementUuid = 'zk-measurement-uuid'): array
    {
        return $sourceFakes + [
            '*/api/v1/kpis?*' => Http::response(['data' => [['uuid' => 'zk-kpi-uuid', 'kpi_code' => 'X']]], 200),
            '*/api/v1/kpis/*/measurements' => Http::response(['data' => ['uuid' => $measurementUuid]], 201),
        ];
    }

    public function test_perfex_pull_measurements_computes_invoices_kpi(): void
    {
        Http::fake($this->withZaiKpiSuccess([
            '*/api/invoices*' => Http::response(['result' => [
                ['id' => 1, 'date' => '2026-08-05', 'total' => 100],
                ['id' => 2, 'date' => '2026-08-15', 'total' => 250],
                ['id' => 3, 'date' => '2026-07-01', 'total' => 999], // outside period
            ]], 200),
        ]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'perfex_crm', 'https://dctrd.us/_ERP');
        $this->zaikpiConnector($ws);
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullPerfexCrmMeasurementsAction())->execute([
            'kpi_code' => 'PX-INVOICES',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(2, $result->output['measurement']['value']['count']);
        $this->assertSame(350.0, $result->output['measurement']['value']['sum']);
        $this->assertSame('perfex_crm', $result->output['measurement']['source_application']);
        $this->assertSame('zk-measurement-uuid', $result->output['zaikpi_measurement_uuid']);

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), '/kpis')) {
                return true;
            }
            return $request->hasHeader('authtoken', 'test-token') && ! $request->hasHeader('Authorization');
        });
    }

    public function test_perfex_pull_measurements_computes_lead_conversion_kpi(): void
    {
        Http::fake($this->withZaiKpiSuccess([
            '*/api/leads*' => Http::response(['result' => [
                ['id' => 1, 'dateadded' => '2026-07-20', 'date_converted' => '2026-08-05 10:00:00'],
                ['id' => 2, 'dateadded' => '2026-08-02', 'date_converted' => '2026-08-15 09:00:00'],
                ['id' => 3, 'dateadded' => '2026-08-10', 'date_converted' => null], // not converted
                ['id' => 4, 'dateadded' => '2026-06-01', 'date_converted' => '2026-07-01 10:00:00'], // converted outside period
            ]], 200),
        ]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'perfex_crm', 'https://dctrd.us/_ERP');
        $this->zaikpiConnector($ws);
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullPerfexCrmMeasurementsAction())->execute([
            'kpi_code' => 'PX-LEAD-CONVERSION',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(2, $result->output['measurement']['value']['count']);
    }

    public function test_perfex_pull_measurements_computes_overdue_work_kpi_with_end_of_day_boundary(): void
    {
        // Client-flagged bug, 2026-09-05: a bare-date period_end used to mean exact midnight,
        // wrongly excluding a task due later on that same end date. This task's duedate carries
        // a time component ON the period's own end date and must still count as overdue.
        Http::fake($this->withZaiKpiSuccess([
            '*/api/tasks*' => Http::response(['result' => [
                ['id' => 1, 'duedate' => '2026-08-31 18:00:00', 'status' => 4], // due on the end date, not complete
                ['id' => 2, 'duedate' => '2026-08-15 10:00:00', 'status' => 5], // complete, excluded
            ]], 200),
        ]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'perfex_crm', 'https://dctrd.us/_ERP');
        $this->zaikpiConnector($ws);

        $result = (new PullPerfexCrmMeasurementsAction())->execute([
            'kpi_code' => 'PX-OVERDUE-WORK',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], new ExecutionContext($ws, $connector));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(1, $result->output['measurement']['value']['count']);
    }

    public function test_perfex_task_status_is_computed_but_not_pushed_to_zaikpi(): void
    {
        Http::fake([
            '*/api/tasks*' => Http::response(['result' => [
                ['id' => 1, 'status' => 1], ['id' => 2, 'status' => 5], ['id' => 3, 'status' => 5],
            ]], 200),
        ]);

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'perfex_crm', 'https://dctrd.us/_ERP');
        $this->zaikpiConnector($ws);

        $result = (new PullPerfexCrmMeasurementsAction())->execute([
            'kpi_code' => 'PX-TASK-STATUS',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], new ExecutionContext($ws, $connector));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(['1' => 1, '5' => 2], $result->output['measurement']['value']);
        $this->assertNull($result->output['measurement']['primary_value']);
        $this->assertArrayHasKey('zaikpi_push_skipped', $result->output);

        // No ZaiKPI call at all — nothing to push.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'kpi.dctrd.us'));
    }

    public function test_perfex_pull_measurements_rejects_unapproved_kpi_code(): void
    {
        $ws = $this->workspace();
        $connector = $this->connector($ws, 'perfex_crm', 'https://dctrd.us/_ERP');
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullPerfexCrmMeasurementsAction())->execute([
            'kpi_code' => 'PX-NOT-A-REAL-KPI',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not in the approved', $result->error);
    }

    public function test_perfex_pull_measurements_fails_cleanly_on_api_error(): void
    {
        Http::fake(['*/api/invoices*' => Http::response(['error' => 'Unauthorized'], 401)]);

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'perfex_crm', 'https://dctrd.us/_ERP');
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullPerfexCrmMeasurementsAction())->execute([
            'kpi_code' => 'PX-INVOICES',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('did not succeed', $result->error);
    }

    public function test_perfex_pull_measurements_completes_the_full_pull_to_push_path_in_one_execution(): void
    {
        // Client-requested fix, 2026-09-05: "a normal execution actually performs the complete
        // source → Connector → ZaiKPI flow without requiring the Pull and Push actions to be
        // executed separately or manually." Exactly 3 HTTP calls prove this: 1 source pull, 1
        // ZaiKPI KPI lookup, 1 ZaiKPI measurement push — all from ONE execute() call.
        Http::fake($this->withZaiKpiSuccess([
            '*/api/leads*' => Http::response(['result' => [
                ['id' => 1, 'dateadded' => '2026-08-05', 'date_converted' => null],
            ]], 200),
        ], 'zk-measurement-abc'));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'perfex_crm', 'https://dctrd.us/_ERP');
        $this->zaikpiConnector($ws);

        $result = (new PullPerfexCrmMeasurementsAction())->execute([
            'kpi_code' => 'PX-LEADS',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], new ExecutionContext($ws, $connector));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(1, $result->output['measurement']['value']['count']);
        $this->assertSame('zk-kpi-uuid', $result->output['zaikpi_kpi_uuid']);
        $this->assertSame('zk-measurement-abc', $result->output['zaikpi_measurement_uuid']);
        Http::assertSentCount(3);
    }

    public function test_perfex_pull_measurements_fails_when_zaikpi_has_no_matching_kpi_definition(): void
    {
        Http::fake([
            '*/api/leads*' => Http::response(['result' => [['id' => 1, 'dateadded' => '2026-08-05']]], 200),
            '*/api/v1/kpis?*' => Http::response(['data' => []], 200), // no matching definition
        ]);

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'perfex_crm', 'https://dctrd.us/_ERP');
        $this->zaikpiConnector($ws);

        $result = (new PullPerfexCrmMeasurementsAction())->execute([
            'kpi_code' => 'PX-LEADS',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], new ExecutionContext($ws, $connector));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('failed to deliver it to ZaiKPI', $result->error);
        $this->assertStringContainsString('No approved KPI definition found', $result->error);
    }

    /**
     * Client-requested fix, 2026-09-05: "Please implement a stable/deterministic
     * source_event_uuid or equivalent replay key and add a test proving that the same source/
     * KPI/period cannot create a duplicate measurement."
     */
    public function test_replaying_the_same_kpi_and_period_produces_the_same_replay_key_every_time(): void
    {
        Http::fake($this->withZaiKpiSuccess([
            '*/api/invoices*' => Http::response(['result' => [['id' => 1, 'date' => '2026-08-05', 'total' => 100]]], 200),
        ]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'perfex_crm', 'https://dctrd.us/_ERP');
        $this->zaikpiConnector($ws);

        $input = [
            'kpi_code' => 'PX-INVOICES',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ];

        $first = (new PullPerfexCrmMeasurementsAction())->execute($input, new ExecutionContext($ws, $connector));
        $second = (new PullPerfexCrmMeasurementsAction())->execute($input, new ExecutionContext($ws, $connector));

        $this->assertTrue($first->success, (string) $first->error);
        $this->assertTrue($second->success, (string) $second->error);
        $this->assertSame($first->output['measurement']['external_uuid'], $second->output['measurement']['external_uuid']);
        $this->assertSame($first->output['measurement']['source_event_uuid'], $second->output['measurement']['source_event_uuid']);

        // A different period for the same everything-else must NOT share the replay key.
        $differentPeriod = array_merge($input, ['period_start' => '2026-09-01', 'period_end' => '2026-09-30']);
        $third = (new PullPerfexCrmMeasurementsAction())->execute($differentPeriod, new ExecutionContext($ws, $connector));
        $this->assertNotSame($first->output['measurement']['external_uuid'], $third->output['measurement']['external_uuid']);

        // The actual outbound push to ZaiKPI carries this exact deterministic key — this is what
        // lets ZaiKPI's own replay guard (source_event_uuid match) recognize a re-run. Must
        // specifically require the /measurements POST — returning true for the preliminary KPI
        // lookup GET too would let this pass without ever checking the push itself, since
        // Http::assertSent() only needs ONE recorded request to satisfy the callback
        // (client-flagged fix, 2026-09-09 3rd review).
        Http::assertSent(function ($request) use ($first) {
            return str_contains($request->url(), '/measurements')
                && $request['uuid'] === $first->output['measurement']['external_uuid']
                && $request['source_event_uuid'] === $first->output['measurement']['source_event_uuid'];
        });

        // Idempotency-Key vs. source_event_uuid — the two must not be conflated (client-corrected
        // fix, 2026-09-09 3rd review, reversing the 2026-09-05 fix which over-corrected by
        // dropping the header entirely; see ZaiKpiClient::pushMeasurement()'s docblock for the
        // full history). The accepted Project 1.b contract requires an Idempotency-Key on every
        // write, but it must be FRESH per request — reusing source_event_uuid as if it were that
        // header is what caused the real 2026-09-05 live bug (a genuine replay's body legitimately
        // differs because measured_at is freshly generated, so ZaiKPI's Idempotency middleware
        // 409'd on the reused key). Proven directly here: both measurement pushes carry a header,
        // but two DIFFERENT values, while source_event_uuid in the body stays identical — that
        // combination is exactly what makes a genuine replay safe under the real contract.
        $replayUuid = $first->output['measurement']['external_uuid'];
        $replayPushes = Http::recorded(fn ($request) => str_contains($request->url(), '/measurements') && $request['uuid'] === $replayUuid)
            ->map(fn ($pair) => $pair[0])
            ->values();
        // Exactly the first and second execute() calls above (same period, genuine replay) — the
        // third used a different period and so carries a different uuid, correctly excluded here.
        $this->assertCount(2, $replayPushes, 'Expected exactly the 2 measurement pushes that share this replay key (first + second execute() calls).');
        $idempotencyKeys = $replayPushes->map(fn ($request) => $request->header('Idempotency-Key')[0] ?? null);
        $this->assertNotContains(null, $idempotencyKeys->all(), 'Every measurement push must carry an Idempotency-Key header.');
        $this->assertNotSame($idempotencyKeys[0], $idempotencyKeys[1], 'Idempotency-Key must be fresh per request, never reused as the replay key.');
    }

    /** Client-requested: "tenant/source isolation" needs direct test coverage. */
    public function test_two_different_tenants_never_share_a_replay_key_for_the_same_kpi_and_period(): void
    {
        Http::fake($this->withZaiKpiSuccess(['*/api/invoices*' => Http::response(['result' => []], 200)]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'perfex_crm', 'https://dctrd.us/_ERP');
        $this->zaikpiConnector($ws);

        $base = ['kpi_code' => 'PX-INVOICES', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31'];
        $tenantA = (string) Str::uuid();
        $tenantB = (string) Str::uuid();

        $resultA = (new PullPerfexCrmMeasurementsAction())->execute($base + ['tenant_uuid' => $tenantA], new ExecutionContext($ws, $connector));
        $resultB = (new PullPerfexCrmMeasurementsAction())->execute($base + ['tenant_uuid' => $tenantB], new ExecutionContext($ws, $connector));

        $this->assertTrue($resultA->success, (string) $resultA->error);
        $this->assertTrue($resultB->success, (string) $resultB->error);
        $this->assertSame($tenantA, $resultA->output['measurement']['tenant_uuid']);
        $this->assertSame($tenantB, $resultB->output['measurement']['tenant_uuid']);
        $this->assertNotSame(
            $resultA->output['measurement']['external_uuid'],
            $resultB->output['measurement']['external_uuid'],
            'Two different tenants must never share a replay key for the same kpi_code/period.'
        );
    }

    public function test_rocket_lms_pull_measurements_computes_enrollments_kpi(): void
    {
        Http::fake($this->withZaiKpiSuccess([
            '*/api/development/panel/financial/sales*' => Http::response(['success' => true, 'data' => ['sales' => [
                ['id' => 1, 'buyer_id' => 930, 'type' => 'webinar', 'created_at' => strtotime('2026-08-05'), 'amount' => '60.00', 'total_amount' => '60.00', 'refund_at' => null],
                ['id' => 2, 'buyer_id' => 931, 'type' => 'bundle', 'created_at' => strtotime('2026-08-06'), 'amount' => '100.00', 'total_amount' => '110.00', 'refund_at' => null],
                ['id' => 3, 'buyer_id' => 930, 'type' => 'booking', 'created_at' => strtotime('2026-08-07'), 'amount' => '20.00', 'total_amount' => '20.00', 'refund_at' => null], // not an enrollment type
                ['id' => 4, 'buyer_id' => 932, 'type' => 'webinar', 'created_at' => strtotime('2026-07-01'), 'amount' => '60.00', 'total_amount' => '60.00', 'refund_at' => null], // outside period
            ]]], 200),
        ]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'rocket_lms', 'https://dctrd.us');
        $this->zaikpiConnector($ws);
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullRocketLmsMeasurementsAction())->execute([
            'kpi_code' => 'RL-ENROLLMENTS',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(2, $result->output['measurement']['value']['count']);
        $this->assertSame('rocket_lms', $result->output['measurement']['source_application']);

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), '/kpis')) {
                return true;
            }
            return $request->hasHeader('Authorization', 'Bearer test-token') && $request->hasHeader('x-api-key', 'test-api-key');
        });
    }

    public function test_rocket_lms_pull_measurements_computes_refunds_kpi(): void
    {
        Http::fake($this->withZaiKpiSuccess([
            '*/api/development/panel/financial/sales*' => Http::response(['success' => true, 'data' => ['sales' => [
                ['id' => 1, 'buyer_id' => 930, 'type' => 'webinar', 'created_at' => strtotime('2026-08-05'), 'amount' => '60.00', 'total_amount' => '60.00', 'refund_at' => strtotime('2026-08-10')],
                ['id' => 2, 'buyer_id' => 931, 'type' => 'webinar', 'created_at' => strtotime('2026-08-06'), 'amount' => '60.00', 'total_amount' => '60.00', 'refund_at' => null],
            ]]], 200),
        ]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'rocket_lms', 'https://dctrd.us');
        $this->zaikpiConnector($ws);
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullRocketLmsMeasurementsAction())->execute([
            'kpi_code' => 'RL-REFUNDS',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(1, $result->output['measurement']['value']['count']);
        $this->assertSame(60.0, $result->output['measurement']['value']['sum']);
    }

    public function test_rocket_lms_pull_measurements_computes_subscriptions_kpi(): void
    {
        Http::fake($this->withZaiKpiSuccess([
            '*/api/development/panel/financial/sales*' => Http::response(['success' => true, 'data' => ['sales' => [
                ['id' => 1, 'buyer_id' => 930, 'type' => 'webinar', 'payment_method' => 'subscribe', 'created_at' => strtotime('2026-08-05'), 'amount' => '0.00', 'total_amount' => '0.00', 'refund_at' => null],
                ['id' => 2, 'buyer_id' => 931, 'type' => 'webinar', 'payment_method' => 'credit', 'created_at' => strtotime('2026-08-06'), 'amount' => '60.00', 'total_amount' => '60.00', 'refund_at' => null],
            ]]], 200),
        ]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'rocket_lms', 'https://dctrd.us');
        $this->zaikpiConnector($ws);
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullRocketLmsMeasurementsAction())->execute([
            'kpi_code' => 'RL-SUBSCRIPTIONS',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(1, $result->output['measurement']['value']['count']);
    }

    public function test_rocket_lms_pull_measurements_computes_active_learners_kpi(): void
    {
        Http::fake($this->withZaiKpiSuccess([
            '*/api/development/panel/financial/sales*' => Http::response(['success' => true, 'data' => ['sales' => [
                ['id' => 1, 'buyer_id' => 930, 'type' => 'webinar', 'created_at' => strtotime('2026-08-05'), 'amount' => '10', 'total_amount' => '10', 'refund_at' => null],
                ['id' => 2, 'buyer_id' => 930, 'type' => 'webinar', 'created_at' => strtotime('2026-08-06'), 'amount' => '10', 'total_amount' => '10', 'refund_at' => null], // same buyer again
                ['id' => 3, 'buyer_id' => 931, 'type' => 'webinar', 'created_at' => strtotime('2026-08-07'), 'amount' => '10', 'total_amount' => '10', 'refund_at' => null],
            ]]], 200),
        ]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'rocket_lms', 'https://dctrd.us');
        $this->zaikpiConnector($ws);

        $result = (new PullRocketLmsMeasurementsAction())->execute([
            'kpi_code' => 'RL-ACTIVE-LEARNERS',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], new ExecutionContext($ws, $connector));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(2, $result->output['measurement']['value']['count']); // distinct buyer_id: 930, 931
    }

    public function test_rocket_lms_pull_measurements_computes_vendor_activity_kpi_with_end_of_day_boundary(): void
    {
        Http::fake($this->withZaiKpiSuccess([
            '*/api/development/panel/classes*' => Http::response(['success' => true, 'data' => ['my_classes' => [
                ['id' => 2001, 'created_at' => strtotime('2026-08-31') + 3600 * 20], // 8pm on the end date
            ]]], 200),
        ]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'rocket_lms', 'https://dctrd.us');
        $this->zaikpiConnector($ws);

        $result = (new PullRocketLmsMeasurementsAction())->execute([
            'kpi_code' => 'RL-VENDOR-ACTIVITY',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31', // bare date — must still include the whole end day
        ], new ExecutionContext($ws, $connector));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(1, $result->output['measurement']['value']['count']);
    }

    /**
     * Client-flagged fix, 2026-09-09 review: "the deterministic source_event_uuid... does not
     * include source_entity_uuid/source_entity_type. This can cause two different vendors/
     * entities under the same tenant, with the same KPI and period, to generate the same replay
     * key... Vendor A and Vendor B producing RL-SALES for the same month must generate different
     * source_event_uuid values." Rocket LMS is used because it's the one adapter genuinely scoped
     * per source entity (one Connector = one vendor, see PullRocketLmsMeasurementsAction's own
     * docblock) — this proves both required behaviors at once: same entity replayed → same key
     * (not a duplicate); different entity, same tenant/kpi/period → different key (this is the
     * bug that was found), and that both measurements are actually delivered to ZaiKPI as two
     * independent pushes, each carrying its own vendor identity in `notes` (see ZaiKpiDelivery).
     */
    public function test_rocket_lms_replay_keys_are_isolated_per_vendor_and_stable_within_one_vendor(): void
    {
        Http::fake($this->withZaiKpiSuccess([
            '*/api/development/panel/financial/sales*' => Http::response(['success' => true, 'data' => ['sales' => [
                ['id' => 1, 'buyer_id' => 10, 'type' => 'webinar', 'total_amount' => 50, 'created_at' => strtotime('2026-08-05')],
            ]]], 200),
        ]));

        $ws = $this->workspace();
        $this->zaikpiConnector($ws);
        $vendorA = $this->connector($ws, 'rocket_lms', 'https://dctrd.us', ['vendor_user_id' => 101], 'rocket_lms-vendor-a');
        $vendorB = $this->connector($ws, 'rocket_lms', 'https://dctrd.us', ['vendor_user_id' => 202], 'rocket_lms-vendor-b');

        $input = [
            'kpi_code' => 'RL-SALES',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ];

        $vendorAFirst = (new PullRocketLmsMeasurementsAction())->execute($input, new ExecutionContext($ws, $vendorA));
        $vendorASecond = (new PullRocketLmsMeasurementsAction())->execute($input, new ExecutionContext($ws, $vendorA));
        $vendorBFirst = (new PullRocketLmsMeasurementsAction())->execute($input, new ExecutionContext($ws, $vendorB));

        $this->assertTrue($vendorAFirst->success, (string) $vendorAFirst->error);
        $this->assertTrue($vendorASecond->success, (string) $vendorASecond->error);
        $this->assertTrue($vendorBFirst->success, (string) $vendorBFirst->error);

        // Same vendor, same KPI, same period, replayed → same key (a genuine replay).
        $this->assertSame(
            $vendorAFirst->output['measurement']['source_event_uuid'],
            $vendorASecond->output['measurement']['source_event_uuid'],
            'Replaying the same vendor/KPI/period must produce the same replay key.'
        );

        // Different vendor, same tenant/KPI/period → different key (the client-flagged bug).
        $this->assertNotSame(
            $vendorAFirst->output['measurement']['source_event_uuid'],
            $vendorBFirst->output['measurement']['source_event_uuid'],
            'Two different vendors must never share a replay key for the same kpi_code/period.'
        );
        $this->assertNotSame(
            $vendorAFirst->output['measurement']['external_uuid'],
            $vendorBFirst->output['measurement']['external_uuid'],
        );

        // Both measurements actually reached ZaiKPI as independent pushes, each carrying its own
        // vendor's identity in `notes` — proving the entity identity is genuinely retrievable
        // from the delivered record, not just implied by the (opaque, to a human reader) key.
        Http::assertSent(function ($request) use ($vendorAFirst) {
            if (! str_contains($request->url(), '/measurements') || $request['uuid'] !== $vendorAFirst->output['measurement']['external_uuid']) {
                return false;
            }
            $notes = json_decode($request['notes'] ?? '{}', true);
            return $notes['source_entity_type'] === 'vendor' && $notes['source_entity_uuid'] === '101';
        });
        Http::assertSent(function ($request) use ($vendorBFirst) {
            if (! str_contains($request->url(), '/measurements') || $request['uuid'] !== $vendorBFirst->output['measurement']['external_uuid']) {
                return false;
            }
            $notes = json_decode($request['notes'] ?? '{}', true);
            return $notes['source_entity_type'] === 'vendor' && $notes['source_entity_uuid'] === '202';
        });
    }

    public function test_rocket_lms_pull_measurements_rejects_unapproved_kpi(): void
    {
        $ws = $this->workspace();
        $connector = $this->connector($ws, 'rocket_lms', 'https://dctrd.us');
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullRocketLmsMeasurementsAction())->execute([
            'kpi_code' => 'RL-NOT-A-REAL-KPI',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not in the approved', $result->error);
    }

    /**
     * Client-flagged fix, 2026-09-09 3rd review: "Rocket LMS execution can still continue with a
     * null vendor_user_id; this should fail closed, otherwise two connectors without a proper
     * vendor identity can still collide." No Http::fake() needed — this must fail before any API
     * call is made.
     */
    public function test_rocket_lms_pull_measurements_fails_closed_without_a_vendor_user_id(): void
    {
        $ws = $this->workspace();
        $connector = $this->connector($ws, 'rocket_lms', 'https://dctrd.us', ['vendor_user_id' => null]);
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullRocketLmsMeasurementsAction())->execute([
            'kpi_code' => 'RL-SALES',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('vendor_user_id', (string) $result->error);
        Http::assertNothingSent();
    }

    public function test_rocket_lms_course_completion_averages_across_vendors_own_courses(): void
    {
        Http::fake($this->withZaiKpiSuccess([
            '*/api/development/panel/classes*' => Http::response(['success' => true, 'data' => ['my_classes' => [
                ['id' => 2001, 'created_at' => strtotime('2026-08-05')],
                ['id' => 2003, 'created_at' => strtotime('2026-08-06')],
            ]]], 200),
            '*/api/development/panel/webinars/2001/statistic*' => Http::response(['success' => true, 'data' => ['webinar' => ['id' => 2001, 'course_progress' => 40]]], 200),
            '*/api/development/panel/webinars/2003/statistic*' => Http::response(['success' => true, 'data' => ['webinar' => ['id' => 2003, 'course_progress' => 60]]], 200),
        ]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'rocket_lms', 'https://dctrd.us');
        $this->zaikpiConnector($ws);
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullRocketLmsMeasurementsAction())->execute([
            'kpi_code' => 'RL-COURSE-COMPLETION',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(2, $result->output['measurement']['value']['courses']);
        $this->assertSame(50.0, $result->output['measurement']['value']['average_progress']);
    }

    public function test_mirotalk_pull_measurements_computes_active_rooms_and_users(): void
    {
        Http::fake($this->withZaiKpiSuccess([
            '*/api/v1/stats*' => Http::response(['success' => true, 'timestamp' => now()->toIso8601String(), 'totalRooms' => 3, 'totalUsers' => 7], 200),
        ]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'mirotalk', 'https://11161115.xyz');
        $this->zaikpiConnector($ws);
        $context = new ExecutionContext($ws, $connector);

        $rooms = (new PullMiroTalkMeasurementsAction())->execute([
            'kpi_code' => 'MT-ACTIVE-ROOMS',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);
        $this->assertTrue($rooms->success, (string) $rooms->error);
        $this->assertSame(3, $rooms->output['measurement']['value']['count']);

        $users = (new PullMiroTalkMeasurementsAction())->execute([
            'kpi_code' => 'MT-ACTIVE-USERS',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);
        $this->assertTrue($users->success, (string) $users->error);
        $this->assertSame(7, $users->output['measurement']['value']['count']);

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), '/kpis')) {
                return true;
            }
            return $request->hasHeader('authorization', 'test-mirotalk-secret');
        });
    }

    public function test_mirotalk_pull_measurements_rejects_unapproved_kpi(): void
    {
        $ws = $this->workspace();
        $connector = $this->connector($ws, 'mirotalk', 'https://11161115.xyz');
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullMiroTalkMeasurementsAction())->execute([
            'kpi_code' => 'MT-NOT-A-REAL-KPI',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not in the approved', $result->error);
    }

    public function test_tour_guide_pull_measurements_computes_completion_rate_kpi(): void
    {
        Http::fake($this->withZaiKpiSuccess([
            // More specific pattern MUST come first — Http::fake() uses first-match-wins, and
            // '*/v1/content*' would otherwise also match '/v1/content-sessions...' requests.
            '*/v1/content-sessions*' => function ($request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $contentId = $query['contentId'] ?? null;

                return Http::response(['results' => match ($contentId) {
                    'content-1' => [
                        ['id' => 's1', 'contentId' => 'content-1', 'userId' => 'u1', 'completed' => true, 'createdAt' => '2026-08-05T00:00:00Z'],
                        ['id' => 's2', 'contentId' => 'content-1', 'userId' => 'u2', 'completed' => false, 'createdAt' => '2026-08-06T00:00:00Z'],
                    ],
                    'content-2' => [
                        ['id' => 's3', 'contentId' => 'content-2', 'userId' => 'u1', 'completed' => true, 'createdAt' => '2026-08-07T00:00:00Z'],
                        ['id' => 's4', 'contentId' => 'content-2', 'userId' => 'u3', 'completed' => false, 'createdAt' => '2026-07-01T00:00:00Z'], // outside period
                    ],
                    default => [],
                }, 'next' => null, 'previous' => null], 200);
            },
            '*/v1/content*' => Http::response(['results' => [
                ['id' => 'content-1', 'object' => 'content'],
                ['id' => 'content-2', 'object' => 'content'],
            ], 'next' => null, 'previous' => null], 200),
        ]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'tour_guide', 'https://usertour.dctrd.us');
        $this->zaikpiConnector($ws);
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullTourGuideMeasurementsAction())->execute([
            'kpi_code' => 'TG-COMPLETION-RATE',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01T00:00:00Z',
            'period_end' => '2026-08-31T23:59:59Z',
        ], $context);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(3, $result->output['measurement']['value']['total']);
        $this->assertSame(2, $result->output['measurement']['value']['completed']);

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), '/kpis')) {
                return true;
            }
            return $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    public function test_tour_guide_pull_measurements_computes_guide_starts_kpi(): void
    {
        Http::fake($this->withZaiKpiSuccess([
            '*/v1/content-sessions*' => Http::response(['results' => [
                ['id' => 's1', 'contentId' => 'content-1', 'userId' => 'u1', 'completed' => true, 'createdAt' => '2026-08-05T00:00:00Z'],
                ['id' => 's2', 'contentId' => 'content-1', 'userId' => 'u2', 'completed' => false, 'createdAt' => '2026-08-06T00:00:00Z'],
            ], 'next' => null], 200),
            '*/v1/content*' => Http::response(['results' => [['id' => 'content-1']], 'next' => null], 200),
        ]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'tour_guide', 'https://usertour.dctrd.us');
        $this->zaikpiConnector($ws);

        $result = (new PullTourGuideMeasurementsAction())->execute([
            'kpi_code' => 'TG-GUIDE-STARTS',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01T00:00:00Z',
            'period_end' => '2026-08-31T23:59:59Z',
        ], new ExecutionContext($ws, $connector));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(2, $result->output['measurement']['value']['count']);
    }

    /** Client-flagged bug, 2026-09-05: only the first page of results was ever read. */
    public function test_tour_guide_pull_measurements_follows_pagination_across_multiple_pages(): void
    {
        Http::fake($this->withZaiKpiSuccess([
            // More specific pattern MUST come first — Http::fake() uses first-match-wins, and
            // '*/v1/content*' would otherwise also match '/v1/content-sessions...' requests.
            '*/v1/content-sessions*' => function ($request) {
                if (str_contains($request->url(), 'cursor=page2')) {
                    return Http::response(['results' => [
                        ['id' => 's2', 'contentId' => 'content-1', 'userId' => 'u2', 'completed' => true, 'createdAt' => '2026-08-06T00:00:00Z'],
                    ], 'next' => null], 200);
                }

                return Http::response(['results' => [
                    ['id' => 's1', 'contentId' => 'content-1', 'userId' => 'u1', 'completed' => true, 'createdAt' => '2026-08-05T00:00:00Z'],
                ], 'next' => '/v1/content-sessions?cursor=page2&contentId=content-1'], 200);
            },
            '*/v1/content*' => Http::response(['results' => [['id' => 'content-1']], 'next' => null], 200),
        ]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'tour_guide', 'https://usertour.dctrd.us');
        $this->zaikpiConnector($ws);

        $result = (new PullTourGuideMeasurementsAction())->execute([
            'kpi_code' => 'TG-GUIDE-COMPLETIONS',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01T00:00:00Z',
            'period_end' => '2026-08-31T23:59:59Z',
        ], new ExecutionContext($ws, $connector));

        $this->assertTrue($result->success, (string) $result->error);
        // Both pages' sessions counted — would be 1 if pagination were still broken.
        $this->assertSame(2, $result->output['measurement']['value']['count']);
    }

    /**
     * Client-flagged fix, 2026-09-09 review: "there is currently a safety limit around 50 pages
     * and reaching that limit still results in a successful response... A truncated KPI result
     * must not be reported as successful." Fakes `next` as always non-null so the safety cap
     * (TourGuideClient::MAX_PAGES) is genuinely hit while pages still remain — the fix must
     * report this as a failure, not a falsely-successful partial count.
     */
    public function test_tour_guide_pull_measurements_fails_instead_of_reporting_a_truncated_result_when_the_pagination_safety_limit_is_reached(): void
    {
        Http::fake($this->withZaiKpiSuccess([
            // More specific pattern first — see the pagination test above for why.
            '*/v1/content-sessions*' => Http::response(['results' => [
                ['id' => 's1', 'contentId' => 'content-1', 'userId' => 'u1', 'completed' => true, 'createdAt' => '2026-08-05T00:00:00Z'],
            ], 'next' => '/v1/content-sessions?cursor=always-more&contentId=content-1'], 200), // never terminates
            '*/v1/content*' => Http::response(['results' => [['id' => 'content-1']], 'next' => null], 200),
        ]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'tour_guide', 'https://usertour.dctrd.us');
        $this->zaikpiConnector($ws);

        $result = (new PullTourGuideMeasurementsAction())->execute([
            'kpi_code' => 'TG-GUIDE-COMPLETIONS',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01T00:00:00Z',
            'period_end' => '2026-08-31T23:59:59Z',
        ], new ExecutionContext($ws, $connector));

        $this->assertFalse($result->success, 'A pull that hit the pagination safety limit with pages still remaining must not be reported as successful.');
        $this->assertStringContainsString('pagination safety limit', strtolower((string) $result->error));
        // No push to ZaiKPI must have been attempted from truncated data.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/measurements'));
    }

    public function test_tour_guide_pull_measurements_rejects_unapproved_kpi(): void
    {
        $ws = $this->workspace();
        $connector = $this->connector($ws, 'tour_guide', 'https://usertour.dctrd.us');
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullTourGuideMeasurementsAction())->execute([
            'kpi_code' => 'TG-CHECKLIST-PROGRESS',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not in the approved', $result->error);
    }

    public function test_leadhub_pull_measurements_computes_new_leads_kpi(): void
    {
        Http::fake($this->withZaiKpiSuccess([
            '*/api/v1/leads*' => Http::response(['data' => [
                ['id' => 1, 'status' => 'new', 'created_at' => '2026-08-05T00:00:00Z', 'updated_at' => '2026-08-05T00:00:00Z', 'contacted_at' => null, 'pipeline_stage_id' => null],
                ['id' => 2, 'status' => 'new', 'created_at' => '2026-08-10T00:00:00Z', 'updated_at' => '2026-08-10T00:00:00Z', 'contacted_at' => null, 'pipeline_stage_id' => null],
            ], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 2]], 200),
        ]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'leadhub', 'https://lead.dctrd.us');
        $this->zaikpiConnector($ws);
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullLeadHubMeasurementsAction())->execute([
            'kpi_code' => 'LH-NEW-LEADS',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01T00:00:00Z',
            'period_end' => '2026-08-31T23:59:59Z',
        ], $context);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(2, $result->output['measurement']['value']['count']);
        $this->assertSame('leadhub', $result->output['measurement']['source_application']);

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), '/kpis')) {
                return true;
            }
            return $request->hasHeader('Authorization', 'Bearer lh_test-key');
        });
    }

    public function test_leadhub_pull_measurements_computes_won_lost_kpi_with_end_of_day_boundary(): void
    {
        Http::fake($this->withZaiKpiSuccess([
            '*/api/v1/leads*' => function ($request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return match ($query['status'] ?? null) {
                    'won' => Http::response(['data' => [
                        // On the period's own end date, with a time component — must still count
                        // (client-flagged bug, 2026-09-05: bare-date period_end used to mean
                        // exact midnight, excluding records like this one).
                        ['id' => 1, 'status' => 'won', 'updated_at' => '2026-08-31T20:00:00Z'],
                    ], 'meta' => ['current_page' => 1, 'last_page' => 1]], 200),
                    'lost' => Http::response(['data' => [
                        ['id' => 2, 'status' => 'lost', 'updated_at' => '2026-08-20T00:00:00Z'],
                        ['id' => 3, 'status' => 'lost', 'updated_at' => '2026-07-01T00:00:00Z'], // outside period
                    ], 'meta' => ['current_page' => 1, 'last_page' => 1]], 200),
                    default => Http::response(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1]], 200),
                };
            },
        ]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'leadhub', 'https://lead.dctrd.us');
        $this->zaikpiConnector($ws);
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullLeadHubMeasurementsAction())->execute([
            'kpi_code' => 'LH-WON-LOST',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31', // bare date — must still include the whole end day
        ], $context);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(1, $result->output['measurement']['value']['won']);
        $this->assertSame(1, $result->output['measurement']['value']['lost']);
    }

    public function test_leadhub_pull_measurements_computes_response_time_kpi(): void
    {
        Http::fake($this->withZaiKpiSuccess([
            '*/api/v1/leads*' => Http::response(['data' => [
                ['id' => 1, 'created_at' => '2026-08-05T00:00:00Z', 'contacted_at' => '2026-08-05T02:00:00Z'],
                ['id' => 2, 'created_at' => '2026-08-06T00:00:00Z', 'contacted_at' => '2026-08-06T04:00:00Z'],
                ['id' => 3, 'created_at' => '2026-08-07T00:00:00Z', 'contacted_at' => null], // never contacted
            ], 'meta' => ['current_page' => 1, 'last_page' => 1]], 200),
        ]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'leadhub', 'https://lead.dctrd.us');
        $this->zaikpiConnector($ws);
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullLeadHubMeasurementsAction())->execute([
            'kpi_code' => 'LH-RESPONSE-TIME',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01T00:00:00Z',
            'period_end' => '2026-08-31T23:59:59Z',
        ], $context);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(2, $result->output['measurement']['value']['contacted_count']);
        $this->assertSame(3.0, $result->output['measurement']['value']['average_hours']);
    }

    public function test_leadhub_pull_measurements_computes_stage_conversion_kpi(): void
    {
        Http::fake($this->withZaiKpiSuccess([
            '*/api/v1/leads*' => Http::response(['data' => [
                ['id' => 1, 'created_at' => '2026-08-05T00:00:00Z', 'pipeline_stage_id' => 10],
                ['id' => 2, 'created_at' => '2026-08-06T00:00:00Z', 'pipeline_stage_id' => 11],
            ], 'meta' => ['current_page' => 1, 'last_page' => 1]], 200),
            '*/api/v1/pipelines' => Http::response(['data' => [['id' => 1]]], 200),
            '*/api/v1/pipelines/1/stages' => Http::response(['data' => [
                ['id' => 10, 'is_won' => true],
                ['id' => 11, 'is_won' => false],
            ]], 200),
        ]));

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'leadhub', 'https://lead.dctrd.us');
        $this->zaikpiConnector($ws);

        $result = (new PullLeadHubMeasurementsAction())->execute([
            'kpi_code' => 'LH-STAGE-CONVERSION',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01T00:00:00Z',
            'period_end' => '2026-08-31T23:59:59Z',
        ], new ExecutionContext($ws, $connector));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(2, $result->output['measurement']['value']['total']);
        $this->assertSame(1, $result->output['measurement']['value']['won']);
        $this->assertSame(0.5, $result->output['measurement']['value']['rate']);
    }

    public function test_leadhub_pull_measurements_rejects_unapproved_kpi(): void
    {
        $ws = $this->workspace();
        $connector = $this->connector($ws, 'leadhub', 'https://lead.dctrd.us');
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullLeadHubMeasurementsAction())->execute([
            'kpi_code' => 'LH-PIPELINE-AGING',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not in the approved', $result->error);
    }
}
