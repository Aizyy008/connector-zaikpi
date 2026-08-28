<?php

namespace App\Modules\RocketLms;

use App\Modules\AbstractModule;
use App\Modules\ExecutionContext;
use App\Modules\ExecutionResult;
use App\Modules\ModuleHealth;
use App\Services\RocketLms\RocketLmsClient;
use App\Support\KpiAdapters\AdapterEventEnvelope;
use Illuminate\Support\Str;

/**
 * Rocket LMS KPI adapter (Project 2, requirements doc Milestone 2). Pulls one period's
 * aggregated measurement for an approved KPI (see
 * project_2_v1_files/docs/03-rocket-lms-{audit,data-dictionary}.md).
 *
 * Deliberately covers only the 5 KPIs whose source endpoint is confirmed
 * (RL-ENROLLMENTS, RL-COURSE-COMPLETION, RL-ACTIVE-LEARNERS, RL-SALES, RL-VENDOR-ACTIVITY).
 * RL-REFUNDS and RL-SUBSCRIPTIONS are intentionally NOT implemented — no matching API route
 * was found for either during discovery; per the requirements doc's change-control rule
 * ("no source application is assumed to provide an endpoint... that has not been verified"),
 * they stay out of KPI_CODES until confirmed rather than guessed at.
 */
class PullRocketLmsMeasurementsAction extends AbstractModule
{
    private const KPI_CODES = [
        'RL-ENROLLMENTS', 'RL-COURSE-COMPLETION', 'RL-ACTIVE-LEARNERS', 'RL-SALES', 'RL-VENDOR-ACTIVITY',
    ];

    public function slug(): string
    {
        return 'rocket_lms.pull_measurements';
    }

    public function name(): string
    {
        return 'Rocket LMS · Pull Measurements';
    }

    public function type(): string
    {
        return 'action';
    }

    public function description(): string
    {
        return 'Reads one aggregated KPI measurement (enrollments, sales, course completion, vendor activity) from Rocket LMS for a period, for delivery to ZaiKPI.';
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
            'webinar_id' => 'string', // required only for RL-COURSE-COMPLETION
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
            return ExecutionResult::fail('No Rocket LMS connector bound to this execution.');
        }
        foreach (['kpi_code', 'tenant_uuid', 'period_start', 'period_end'] as $required) {
            if (empty($input[$required])) {
                return ExecutionResult::fail("{$required} is required.");
            }
        }
        if (! in_array($input['kpi_code'], self::KPI_CODES, true)) {
            return ExecutionResult::fail("kpi_code '{$input['kpi_code']}' is not in the approved Rocket LMS KPI catalogue (or its source endpoint is unconfirmed — see 03-rocket-lms-audit.md).");
        }
        if ($input['kpi_code'] === 'RL-COURSE-COMPLETION' && empty($input['webinar_id'])) {
            return ExecutionResult::fail('webinar_id is required for RL-COURSE-COMPLETION.');
        }

        $client = RocketLmsClient::forConnector($context->connector);
        $value = $this->compute($client, $input);

        if ($value === null) {
            return ExecutionResult::fail("Failed to compute {$input['kpi_code']} — the Rocket LMS API call did not succeed.");
        }

        $fields = AdapterEventEnvelope::contractFields([
            'tenant_uuid' => $input['tenant_uuid'],
            'source_application' => 'rocket_lms',
            'source_entity_type' => $input['kpi_code'] === 'RL-COURSE-COMPLETION' ? 'webinar' : null,
            'source_entity_uuid' => $input['webinar_id'] ?? null,
            'external_uuid' => (string) Str::uuid(),
            'kpi_namespace' => 'rocket_lms.' . $this->domainFor($input['kpi_code']),
            'kpi_code' => $input['kpi_code'],
            'kpi_domain' => $this->domainFor($input['kpi_code']),
            'period_start' => $input['period_start'],
            'period_end' => $input['period_end'],
            'measured_at' => now()->toIso8601String(),
        ]);

        return ExecutionResult::ok(['measurement' => $fields + ['value' => $value]]);
    }

    /** Domain per 03-rocket-lms-data-dictionary.md §1. */
    private function domainFor(string $kpiCode): string
    {
        return match ($kpiCode) {
            'RL-ENROLLMENTS', 'RL-COURSE-COMPLETION', 'RL-ACTIVE-LEARNERS' => 'learning',
            default => 'marketplace',
        };
    }

    /**
     * Field names are best-effort (id/status/created_at conventions) — not yet verified against
     * a live response, per the class docblock. Returns null on any API failure.
     */
    private function compute(RocketLmsClient $client, array $input): ?array
    {
        $start = $input['period_start'];
        $end = $input['period_end'];

        return match ($input['kpi_code']) {
            'RL-ENROLLMENTS' => $this->countInPeriod($client->purchases(), 'created_at', $start, $end),
            'RL-ACTIVE-LEARNERS' => $this->distinctUsersInPeriod($client->purchases(), 'created_at', $start, $end),
            'RL-SALES' => $this->countAndSum($client->sales(), 'created_at', 'total', $start, $end),
            'RL-VENDOR-ACTIVITY' => $this->countInPeriod($client->instructorCourses(), 'created_at', $start, $end),
            'RL-COURSE-COMPLETION' => $this->completionFromStatistic($client, $input['webinar_id']),
            default => null,
        };
    }

    private function countInPeriod(array $result, string $dateField, string $start, string $end): ?array
    {
        if (! $result['ok']) {
            return null;
        }
        $rows = collect($result['data'])->filter(fn ($r) => isset($r[$dateField]) && $r[$dateField] >= $start && $r[$dateField] <= $end);

        return ['count' => $rows->count()];
    }

    private function distinctUsersInPeriod(array $result, string $dateField, string $start, string $end): ?array
    {
        if (! $result['ok']) {
            return null;
        }
        $rows = collect($result['data'])->filter(fn ($r) => isset($r[$dateField]) && $r[$dateField] >= $start && $r[$dateField] <= $end);

        return ['count' => $rows->pluck('user_id')->unique()->count()];
    }

    private function countAndSum(array $result, string $dateField, string $sumField, string $start, string $end): ?array
    {
        if (! $result['ok']) {
            return null;
        }
        $rows = collect($result['data'])->filter(fn ($r) => isset($r[$dateField]) && $r[$dateField] >= $start && $r[$dateField] <= $end);

        return ['count' => $rows->count(), 'sum' => (float) $rows->sum($sumField)];
    }

    private function completionFromStatistic(RocketLmsClient $client, string $webinarId): ?array
    {
        $result = $client->webinarStatistic($webinarId);
        if (! $result['ok']) {
            return null;
        }
        $data = $result['data'];

        return [
            'enrolled' => $data['enrolled_count'] ?? $data['students_count'] ?? null,
            'completed' => $data['completed_count'] ?? null,
        ];
    }
}
