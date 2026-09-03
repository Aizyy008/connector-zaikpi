<?php

namespace App\Modules\TourGuide;

use App\Modules\AbstractModule;
use App\Modules\ExecutionContext;
use App\Modules\ExecutionResult;
use App\Modules\ModuleHealth;
use App\Services\TourGuide\TourGuideClient;
use App\Support\KpiAdapters\AdapterEventEnvelope;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Tour Guide (Usertour) KPI adapter (Project 2, requirements doc Milestone 5). Pulls one
 * period's aggregated measurement for an approved KPI, across ALL content (guides/tours) —
 * `GET /v1/content-sessions` requires a `contentId` per call (confirmed live), so this pulls
 * `content` first, then sessions per content id, then aggregates.
 *
 * Field names CONFIRMED 2026-09-03 from the live OpenAPI schema (`ContentSession` component) —
 * real fields are camelCase, NOT the snake_case originally guessed: `completed` (boolean),
 * `createdAt`, `userId`, `contentId`, `progress`. List responses use `{results: [...]}`, not
 * `{data: [...]}`.
 *
 * TG-CHECKLIST-PROGRESS, TG-STEP-DROPOFF and TG-GUIDE-ERRORS remain intentionally NOT
 * implemented — confirmed (not just guessed) there is no way to build them: `event-definitions`
 * only lists event TYPES (e.g. `checklist_completed`, `flow_step_seen`, `tooltip_target_missing`
 * all genuinely exist as concepts), but there is no endpoint anywhere in the API that returns
 * actual event OCCURRENCES, and `ContentSession` itself has no embedded events array. Checked
 * thoroughly via the full OpenAPI schema, not just the path list — this is a confirmed "no",
 * not a gap left from not looking.
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
        $sessions = $this->collectSessions($client, $input);
        if ($sessions === null) {
            return ExecutionResult::fail("Failed to compute {$input['kpi_code']} — the Tour Guide API call did not succeed.");
        }

        $value = $this->compute($input['kpi_code'], $sessions, $input);

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
     * `content-sessions` requires a contentId per call, so pull `content` first (or use the
     * caller-supplied content_id to skip that lookup), then sessions per content id, merged.
     */
    private function collectSessions(TourGuideClient $client, array $input): ?Collection
    {
        if (! empty($input['content_id'])) {
            $contentIds = collect([$input['content_id']]);
        } else {
            $contentResult = $client->listContent();
            if (! $contentResult['ok']) {
                return null;
            }
            $contentIds = collect($contentResult['data'])->pluck('id')->filter();
        }

        $sessions = collect();
        foreach ($contentIds as $contentId) {
            $result = $client->listContentSessions((string) $contentId);
            if (! $result['ok']) {
                return null;
            }
            $sessions = $sessions->merge($result['data']);
        }

        return $sessions->filter(
            fn ($r) => isset($r['createdAt']) && $r['createdAt'] >= $input['period_start'] && $r['createdAt'] <= $input['period_end']
        );
    }

    private function compute(string $kpiCode, Collection $sessions, array $input): array
    {
        $completed = $sessions->filter(fn ($r) => ! empty($r['completed']));

        return match ($kpiCode) {
            'TG-GUIDE-STARTS' => ['count' => $sessions->count()],
            'TG-GUIDE-COMPLETIONS' => ['count' => $completed->count()],
            'TG-COMPLETION-RATE' => [
                'total' => $sessions->count(),
                'completed' => $completed->count(),
                'rate' => $sessions->count() > 0 ? round($completed->count() / $sessions->count(), 4) : null,
            ],
            'TG-FEATURE-ADOPTION' => ['count' => $completed->pluck('userId')->filter()->unique()->count()],
        };
    }
}
