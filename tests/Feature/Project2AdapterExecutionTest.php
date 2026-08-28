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
            '*/api/user/webinars/purchases*' => Http::response(['data' => [
                ['id' => 1, 'user_id' => 10, 'created_at' => '2026-08-05'],
                ['id' => 2, 'user_id' => 11, 'created_at' => '2026-08-06'],
            ]], 200),
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

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_rocket_lms_pull_measurements_rejects_unconfirmed_kpi(): void
    {
        // RL-REFUNDS / RL-SUBSCRIPTIONS deliberately excluded — no confirmed source endpoint.
        $ws = $this->workspace();
        $connector = $this->connector($ws, 'rocket_lms', 'https://dctrd.us');
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullRocketLmsMeasurementsAction())->execute([
            'kpi_code' => 'RL-REFUNDS',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('unconfirmed', $result->error);
    }

    public function test_rocket_lms_course_completion_requires_webinar_id(): void
    {
        $ws = $this->workspace();
        $connector = $this->connector($ws, 'rocket_lms', 'https://dctrd.us');
        $context = new ExecutionContext($ws, $connector);

        $result = (new PullRocketLmsMeasurementsAction())->execute([
            'kpi_code' => 'RL-COURSE-COMPLETION',
            'tenant_uuid' => (string) Str::uuid(),
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('webinar_id is required', $result->error);
    }
}
