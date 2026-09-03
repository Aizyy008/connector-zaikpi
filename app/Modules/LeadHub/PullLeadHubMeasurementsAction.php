<?php

namespace App\Modules\LeadHub;

use App\Modules\AbstractModule;
use App\Modules\ExecutionContext;
use App\Modules\ExecutionResult;
use App\Modules\ModuleHealth;
use App\Services\LeadHub\LeadHubClient;
use App\Support\KpiAdapters\AdapterEventEnvelope;
use Illuminate\Support\Str;

/**
 * LeadHub KPI adapter (Project 2, requirements doc Milestone 4). Pulls one period's aggregated
 * measurement for an approved KPI from LeadHub's real REST API (see
 * project_2_v1_files/docs/09-leadhub-{audit,data-dictionary}.md).
 *
 * IMPORTANT — LeadHub's own event log (`lead_activities`: status_changed, stage_moved, etc.) has
 * NO REST endpoint (confirmed by reading the full routes/api.php, not a gap from missing
 * access). So `LH-QUALIFIED-LEADS` and `LH-WON-LOST` use `updated_at` on the `leads` resource as
 * the best available proxy for "when this happened" — NOT a true event timestamp. Documented
 * here so this is never mistaken for exact event tracking downstream.
 *
 * `LH-PIPELINE-AGING` is intentionally NOT implemented — `stage_entered_at` is a real column on
 * the `leads` table but is confirmed absent from the API response (`LeadResource::toArray()`
 * read in full — it simply isn't returned). A real, source-confirmed obstruction, not a guess.
 */
class PullLeadHubMeasurementsAction extends AbstractModule
{
    private const KPI_CODES = [
        'LH-NEW-LEADS', 'LH-QUALIFIED-LEADS', 'LH-WON-LOST', 'LH-RESPONSE-TIME', 'LH-STAGE-CONVERSION',
    ];

    public function slug(): string
    {
        return 'leadhub.pull_measurements';
    }

    public function name(): string
    {
        return 'LeadHub · Pull Measurements';
    }

    public function type(): string
    {
        return 'action';
    }

    public function description(): string
    {
        return 'Reads one aggregated lead-pipeline KPI measurement (new/qualified leads, won/lost outcomes, response time, stage conversion) from LeadHub for a period, for delivery to ZaiKPI.';
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
            return ExecutionResult::fail('No LeadHub connector bound to this execution.');
        }
        foreach (['kpi_code', 'tenant_uuid', 'period_start', 'period_end'] as $required) {
            if (empty($input[$required])) {
                return ExecutionResult::fail("{$required} is required.");
            }
        }
        if (! in_array($input['kpi_code'], self::KPI_CODES, true)) {
            return ExecutionResult::fail("kpi_code '{$input['kpi_code']}' is not in the approved LeadHub KPI catalogue (or its source field is unconfirmed — see 09-leadhub-data-dictionary.md).");
        }

        $client = LeadHubClient::forConnector($context->connector);
        $value = $this->compute($client, $input['kpi_code'], $input['period_start'], $input['period_end']);

        if ($value === null) {
            return ExecutionResult::fail("Failed to compute {$input['kpi_code']} — the LeadHub API call did not succeed.");
        }

        $fields = AdapterEventEnvelope::contractFields([
            'tenant_uuid' => $input['tenant_uuid'],
            'source_application' => 'leadhub',
            'source_entity_type' => 'lead',
            'external_uuid' => (string) Str::uuid(),
            'kpi_namespace' => 'leadhub.pipeline',
            'kpi_code' => $input['kpi_code'],
            'kpi_domain' => 'pipeline',
            'period_start' => $input['period_start'],
            'period_end' => $input['period_end'],
            'measured_at' => now()->toIso8601String(),
            // Inherits an inbound correlation id when supplied via ExecutionContext::$meta —
            // see PullPerfexCrmMeasurementsAction for the full rationale.
            'correlation_id' => $context->meta['correlation_id'] ?? null,
        ]);

        return ExecutionResult::ok(['measurement' => $fields + ['value' => $value]]);
    }

    private function compute(LeadHubClient $client, string $kpiCode, string $start, string $end): ?array
    {
        return match ($kpiCode) {
            'LH-NEW-LEADS' => $this->newLeads($client, $start, $end),
            'LH-QUALIFIED-LEADS' => $this->statusCountInPeriod($client, 'qualified', $start, $end),
            'LH-WON-LOST' => $this->wonLost($client, $start, $end),
            'LH-RESPONSE-TIME' => $this->responseTime($client, $start, $end),
            'LH-STAGE-CONVERSION' => $this->stageConversion($client, $start, $end),
            default => null,
        };
    }

    private function newLeads(LeadHubClient $client, string $start, string $end): ?array
    {
        $result = $client->leadsCreatedInPeriod($start, $end);
        if (! $result['ok']) {
            return null;
        }

        return ['count' => count($result['data'])];
    }

    /** `updated_at`-filtered — best available proxy, see class docblock. */
    private function statusCountInPeriod(LeadHubClient $client, string $status, string $start, string $end): ?array
    {
        $result = $client->leadsByStatus($status);
        if (! $result['ok']) {
            return null;
        }
        $count = collect($result['data'])->filter(
            fn ($r) => isset($r['updated_at']) && $r['updated_at'] >= $start && $r['updated_at'] <= $end
        )->count();

        return ['count' => $count];
    }

    private function wonLost(LeadHubClient $client, string $start, string $end): ?array
    {
        $won = $this->statusCountInPeriod($client, 'won', $start, $end);
        $lost = $this->statusCountInPeriod($client, 'lost', $start, $end);
        if ($won === null || $lost === null) {
            return null;
        }

        return ['won' => $won['count'], 'lost' => $lost['count']];
    }

    private function responseTime(LeadHubClient $client, string $start, string $end): ?array
    {
        $result = $client->leadsCreatedInPeriod($start, $end);
        if (! $result['ok']) {
            return null;
        }
        $contacted = collect($result['data'])->filter(fn ($r) => ! empty($r['contacted_at']) && ! empty($r['created_at']));
        if ($contacted->isEmpty()) {
            return ['contacted_count' => 0, 'average_hours' => null];
        }
        $avgHours = $contacted->map(
            fn ($r) => (strtotime($r['contacted_at']) - strtotime($r['created_at'])) / 3600
        )->avg();

        return ['contacted_count' => $contacted->count(), 'average_hours' => round($avgHours, 2)];
    }

    private function stageConversion(LeadHubClient $client, string $start, string $end): ?array
    {
        $leadsResult = $client->leadsCreatedInPeriod($start, $end);
        if (! $leadsResult['ok']) {
            return null;
        }
        $leads = collect($leadsResult['data'])->filter(fn ($r) => ! empty($r['pipeline_stage_id']));
        if ($leads->isEmpty()) {
            return ['total' => 0, 'won' => 0, 'rate' => null];
        }

        $pipelinesResult = $client->pipelines();
        if (! $pipelinesResult['ok']) {
            return null;
        }
        $wonStageIds = collect();
        foreach (collect($pipelinesResult['data'])->pluck('id') as $pipelineId) {
            $stagesResult = $client->pipelineStages((int) $pipelineId);
            if (! $stagesResult['ok']) {
                return null;
            }
            $wonStageIds = $wonStageIds->merge(
                collect($stagesResult['data'])->filter(fn ($s) => ! empty($s['is_won']))->pluck('id')
            );
        }

        $won = $leads->filter(fn ($r) => $wonStageIds->contains($r['pipeline_stage_id']))->count();

        return ['total' => $leads->count(), 'won' => $won, 'rate' => round($won / $leads->count(), 4)];
    }
}
