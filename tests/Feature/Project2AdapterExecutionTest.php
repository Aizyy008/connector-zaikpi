<?php

namespace Tests\Feature;

use App\Models\Connector;
use App\Models\ConnectorCredential;
use App\Models\Workspace;
use App\Modules\ExecutionContext;
use App\Modules\PerfexCrm\PullPerfexCrmMeasurementsAction;
use App\Modules\RocketLms\PullRocketLmsMeasurementsAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Project 2 KPI adapters — Perfex CRM and Rocket LMS Pull actions. Mirrors ExecutionTest.php's
 * conventions (Http::fake() rather than a live call, since no real credential exists yet for
 * either app — see project_2_v1_files/docs/{03,04}-*-audit.md).
 */
class Project2AdapterExecutionTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): Workspace
    {
        return Workspace::create(['name' => 'Test', 'slug' => 'test-' . Str::random(8), 'environment' => 'staging', 'status' => 'active']);
    }

    private function connector(Workspace $ws, string $slug, string $baseUrl, array $config = []): Connector
    {
        $connector = Connector::create([
            'workspace_id' => $ws->id, 'name' => $slug, 'slug' => $slug, 'type' => 'kpi_adapter',
            'provider' => $slug, 'role' => 'source', 'status' => 'healthy', 'enabled' => true,
            'config' => ['base_url' => $baseUrl, 'timeout' => 10] + $config,
        ]);
        $cred = new ConnectorCredential(['connector_id' => $connector->id, 'key' => 'api_token', 'type' => 'secret']);
        $cred->setSecret('test-token');
        $cred->save();

        if ($slug === 'rocket_lms') {
            $apiKeyCred = new ConnectorCredential(['connector_id' => $connector->id, 'key' => 'api_key', 'type' => 'secret']);
            $apiKeyCred->setSecret('test-api-key');
            $apiKeyCred->save();
        }

        return $connector->fresh(['credentials']);
    }

    public function test_perfex_pull_measurements_computes_invoices_kpi(): void
    {
        Http::fake([
            '*/api/invoices*' => Http::response(['result' => [
                ['id' => 1, 'date' => '2026-08-05', 'total' => 100],
                ['id' => 2, 'date' => '2026-08-15', 'total' => 250],
                ['id' => 3, 'date' => '2026-07-01', 'total' => 999], // outside period
            ]], 200),
        ]);

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'perfex_crm', 'https://dctrd.us/_ERP');
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullPerfexCrmMeasurementsAction())->execute([
            'kpi_code' => 'PX-INVOICES',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->output['measurement']['value']['count']);
        $this->assertSame(350.0, $result->output['measurement']['value']['sum']);
        $this->assertSame('perfex_crm', $result->output['measurement']['source_application']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('authtoken', 'test-token')
                && ! $request->hasHeader('Authorization');
        });
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

    public function test_rocket_lms_pull_measurements_computes_enrollments_kpi(): void
    {
        Http::fake([
            '*/api/development/panel/financial/sales*' => Http::response(['success' => true, 'data' => ['sales' => [
                ['id' => 1, 'buyer_id' => 930, 'type' => 'webinar', 'created_at' => strtotime('2026-08-05'), 'amount' => '60.00', 'total_amount' => '60.00', 'refund_at' => null],
                ['id' => 2, 'buyer_id' => 931, 'type' => 'bundle', 'created_at' => strtotime('2026-08-06'), 'amount' => '100.00', 'total_amount' => '110.00', 'refund_at' => null],
                ['id' => 3, 'buyer_id' => 930, 'type' => 'booking', 'created_at' => strtotime('2026-08-07'), 'amount' => '20.00', 'total_amount' => '20.00', 'refund_at' => null], // not an enrollment type
                ['id' => 4, 'buyer_id' => 932, 'type' => 'webinar', 'created_at' => strtotime('2026-07-01'), 'amount' => '60.00', 'total_amount' => '60.00', 'refund_at' => null], // outside period
            ]]], 200),
        ]);

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'rocket_lms', 'https://dctrd.us');
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullRocketLmsMeasurementsAction())->execute([
            'kpi_code' => 'RL-ENROLLMENTS',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->output['measurement']['value']['count']);
        $this->assertSame('rocket_lms', $result->output['measurement']['source_application']);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('x-api-key', 'test-api-key'));
    }

    public function test_rocket_lms_pull_measurements_computes_refunds_kpi(): void
    {
        Http::fake([
            '*/api/development/panel/financial/sales*' => Http::response(['success' => true, 'data' => ['sales' => [
                ['id' => 1, 'buyer_id' => 930, 'type' => 'webinar', 'created_at' => strtotime('2026-08-05'), 'amount' => '60.00', 'total_amount' => '60.00', 'refund_at' => strtotime('2026-08-10')],
                ['id' => 2, 'buyer_id' => 931, 'type' => 'webinar', 'created_at' => strtotime('2026-08-06'), 'amount' => '60.00', 'total_amount' => '60.00', 'refund_at' => null],
            ]]], 200),
        ]);

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'rocket_lms', 'https://dctrd.us');
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullRocketLmsMeasurementsAction())->execute([
            'kpi_code' => 'RL-REFUNDS',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->output['measurement']['value']['count']);
        $this->assertSame(60.0, $result->output['measurement']['value']['sum']);
    }

    public function test_rocket_lms_pull_measurements_computes_subscriptions_kpi(): void
    {
        Http::fake([
            '*/api/development/panel/financial/sales*' => Http::response(['success' => true, 'data' => ['sales' => [
                ['id' => 1, 'buyer_id' => 930, 'type' => 'webinar', 'payment_method' => 'subscribe', 'created_at' => strtotime('2026-08-05'), 'amount' => '0.00', 'total_amount' => '0.00', 'refund_at' => null],
                ['id' => 2, 'buyer_id' => 931, 'type' => 'webinar', 'payment_method' => 'credit', 'created_at' => strtotime('2026-08-06'), 'amount' => '60.00', 'total_amount' => '60.00', 'refund_at' => null],
            ]]], 200),
        ]);

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'rocket_lms', 'https://dctrd.us');
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullRocketLmsMeasurementsAction())->execute([
            'kpi_code' => 'RL-SUBSCRIPTIONS',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->output['measurement']['value']['count']);
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

    public function test_rocket_lms_course_completion_averages_across_vendors_own_courses(): void
    {
        Http::fake([
            '*/api/development/panel/classes*' => Http::response(['success' => true, 'data' => ['my_classes' => [
                ['id' => 2001, 'created_at' => strtotime('2026-08-05')],
                ['id' => 2003, 'created_at' => strtotime('2026-08-06')],
            ]]], 200),
            '*/api/development/panel/webinars/2001/statistic*' => Http::response(['success' => true, 'data' => ['webinar' => ['id' => 2001, 'course_progress' => 40]]], 200),
            '*/api/development/panel/webinars/2003/statistic*' => Http::response(['success' => true, 'data' => ['webinar' => ['id' => 2003, 'course_progress' => 60]]], 200),
        ]);

        $ws = $this->workspace();
        $connector = $this->connector($ws, 'rocket_lms', 'https://dctrd.us');
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullRocketLmsMeasurementsAction())->execute([
            'kpi_code' => 'RL-COURSE-COMPLETION',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->output['measurement']['value']['courses']);
        $this->assertSame(50.0, $result->output['measurement']['value']['average_progress']);
    }
}
