<?php

namespace App\Modules\TourGuide;

use App\Modules\AbstractModule;
use App\Modules\ExecutionContext;
use App\Modules\ExecutionResult;
use App\Modules\ModuleHealth;
use App\Services\TourGuide\TourGuideClient;
use App\Support\KpiAdapters\AdapterEventEnvelope;
use Illuminate\Support\Str;

/**
 * Tour Guide (Usertour) KPI adapter (Project 2, requirements doc Milestone 5). Pulls one
 * period's aggregated measurement for an approved KPI, all from one `content-sessions` pull
 * (see project_2_v1_files/docs/{01,02,06}-tour-guide-*.md).
 *
 * Deliberately covers only the 4 KPIs whose source field is confirmed
 * (TG-GUIDE-STARTS, TG-GUIDE-COMPLETIONS, TG-COMPLETION-RATE, TG-FEATURE-ADOPTION).
 * TG-CHECKLIST-PROGRESS, TG-STEP-DROPOFF and TG-GUIDE-ERRORS are intentionally NOT implemented —
 * no matching field found in the path-only OpenAPI spec; per the requirements doc's change-
 * control rule, they stay out of KPI_CODES until confirmed against a real response rather than
 * guessed at.
 */
class PullTourGuideMeasurementsAction extends AbstractModule
{
    private const KPI_CODES = [
        'TG-GUIDE-STARTS', 'TG-GUIDE-COMPLETIONS', 'TG-COMPLETION-RATE', 'TG-FEATURE-ADOPTION',
    ];

    public function slug(): string
    {
        return 'tour_guide.pull_measurements';
    }

    public function name(): string
    {
        return 'Tour Guide · Pull Measurements';
    }

    public function type(): string
    {
        return 'action';
    }

    public function description(): string
    {
        return 'Reads one aggregated onboarding/adoption KPI measurement from Tour Guide (Usertour) for a period, for delivery to ZaiKPI.';
    }

    public function actions(): array
    {
        return ['pull_measurements'];
    }

    public function inputSchema(): array
    {
        return [
            'kpi_code' => 'string',
            'tenant_uuid' => 'string',
            'period_start' => 'string',
            'period_end' => 'string',
            'content_id' => 'string', // optional: scope to one guide/tour
        ];
    }

    public function outputSchema(): array
    {
        return ['measurement' => 'object'];
    }

    public function scopes(): array
    {
        return ['flows.execute', 'measurements:read'];
    }

    public function healthCheck(): ModuleHealth
    {
        return ModuleHealth::Healthy;
    }

    public function execute(array $input, ExecutionContext $context): ExecutionResult
    {
        if (! $context->connector) {
            return ExecutionResult::fail('No Tour Guide connector bound to this execution.');
        }
        foreach (['kpi_code', 'tenant_uuid', 'period_start', 'period_end'] as $required) {
            if (empty($input[$required])) {
                return ExecutionResult::fail("{$required} is required.");
            }
        }
        if (! in_array($input['kpi_code'], self::KPI_CODES, true)) {
            return ExecutionResult::fail("kpi_code '{$input['kpi_code']}' is not in the approved Tour Guide KPI catalogue (or its source field is unconfirmed — see 02-tour-guide-data-dictionary.md).");
        }

        $client = TourGuideClient::forConnector($context->connector);
        $value = $this->compute($client, $input);

        if ($value === null) {
            return ExecutionResult::fail("Failed to compute {$input['kpi_code']} — the Tour Guide API call did not succeed.");
        }

        $fields = AdapterEventEnvelope::contractFields([
            'tenant_uuid' => $input['tenant_uuid'],
            'source_application' => 'tour_guide',
            'source_entity_type' => 'content',
            'source_entity_uuid' => $input['content_id'] ?? null,
            'external_uuid' => (string) Str::uuid(),
            'kpi_namespace' => 'tour_guide.onboarding',
            'kpi_code' => $input['kpi_code'],
            'kpi_domain' => 'onboarding',
            'period_start' => $input['period_start'],
            'period_end' => $input['period_end'],
            'measured_at' => now()->toIso8601String(),
        ]);

        return ExecutionResult::ok(['measurement' => $fields + ['value' => $value]]);
    }

    /**
     * Field names are best-effort (id/content_id/user_id/created_at/ended_at conventions) — not
     * yet verified against a live response, per the class docblock. Returns null on any API
     * failure.
     */
    private function compute(TourGuideClient $client, array $input): ?array
    {
        $result = $client->listContentSessions();
        if (! $result['ok']) {
            return null;
        }
        $rows = collect($result['data'])->filter(
            fn ($r) => isset($r['created_at']) && $r['created_at'] >= $input['period_start'] && $r['created_at'] <= $input['period_end']
        );
        if (! empty($input['content_id'])) {
            $rows = $rows->filter(fn ($r) => ($r['content_id'] ?? null) === $input['content_id']);
        }
        $completed = $rows->filter(fn ($r) => ! empty($r['ended_at']));

        return match ($input['kpi_code']) {
            'TG-GUIDE-STARTS' => ['count' => $rows->count()],
            'TG-GUIDE-COMPLETIONS' => ['count' => $completed->count()],
            'TG-COMPLETION-RATE' => [
                'total' => $rows->count(),
                'completed' => $completed->count(),
                'rate' => $rows->count() > 0 ? round($completed->count() / $rows->count(), 4) : null,
            ],
            'TG-FEATURE-ADOPTION' => ['count' => $completed->pluck('user_id')->unique()->count()],
            default => null,
        };
    }
}
