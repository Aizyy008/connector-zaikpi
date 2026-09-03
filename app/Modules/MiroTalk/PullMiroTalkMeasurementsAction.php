<?php

namespace App\Modules\MiroTalk;

use App\Modules\AbstractModule;
use App\Modules\ExecutionContext;
use App\Modules\ExecutionResult;
use App\Modules\ModuleHealth;
use App\Services\MiroTalk\MiroTalkClient;
use App\Support\KpiAdapters\AdapterEventEnvelope;
use Illuminate\Support\Str;

/**
 * MiroTalk KPI adapter (Project 2). Pulls a live snapshot measurement for an approved KPI from
 * `GET /api/v1/stats` (see project_2_v1_files/docs/05-mirotalk-{audit,data-dictionary}.md).
 *
 * IMPORTANT — these are GAUGES, not period counts. `GET /api/v1/meetings` (the only endpoint
 * that would give period-based/per-meeting detail) is confirmed disabled on this deployment, so
 * both KPIs report "as of period_end" rather than "count within [period_start, period_end]"
 * like every other adapter in this project. `period_start` is accepted (contract requirement)
 * but not used in the calculation — documented here so this is never mistaken for a
 * period-summed count downstream.
 *
 * Scope: read-only, no media content/recordings/meeting payloads — matches the requirements
 * doc's exclusion for this adapter and this adapter's "pure Connector-side module" decision.
 */
class PullMiroTalkMeasurementsAction extends AbstractModule
{
    private const KPI_CODES = ['MT-ACTIVE-ROOMS', 'MT-ACTIVE-USERS'];

    public function slug(): string
    {
        return 'mirotalk.pull_measurements';
    }

    public function name(): string
    {
        return 'MiroTalk · Pull Measurements';
    }

    public function type(): string
    {
        return 'action';
    }

    public function description(): string
    {
        return 'Reads a live snapshot measurement (active rooms, active users) from MiroTalk, for delivery to ZaiKPI.';
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
            return ExecutionResult::fail('No MiroTalk connector bound to this execution.');
        }
        foreach (['kpi_code', 'tenant_uuid', 'period_start', 'period_end'] as $required) {
            if (empty($input[$required])) {
                return ExecutionResult::fail("{$required} is required.");
            }
        }
        if (! in_array($input['kpi_code'], self::KPI_CODES, true)) {
            return ExecutionResult::fail("kpi_code '{$input['kpi_code']}' is not in the approved MiroTalk KPI catalogue.");
        }

        $client = MiroTalkClient::forConnector($context->connector);
        $result = $client->stats();
        if (! $result['ok']) {
            return ExecutionResult::fail("Failed to compute {$input['kpi_code']} — the MiroTalk API call did not succeed.");
        }

        $value = match ($input['kpi_code']) {
            'MT-ACTIVE-ROOMS' => ['count' => (int) ($result['data']['totalRooms'] ?? 0)],
            'MT-ACTIVE-USERS' => ['count' => (int) ($result['data']['totalUsers'] ?? 0)],
            default => null,
        };

        $fields = AdapterEventEnvelope::contractFields([
            'tenant_uuid' => $input['tenant_uuid'],
            'source_application' => 'mirotalk',
            'source_entity_type' => 'snapshot',
            'external_uuid' => (string) Str::uuid(),
            'kpi_namespace' => 'mirotalk.usage',
            'kpi_code' => $input['kpi_code'],
            'kpi_domain' => 'usage',
            'period_start' => $input['period_start'],
            'period_end' => $input['period_end'],
            'measured_at' => now()->toIso8601String(),
            // Inherits an inbound correlation id when supplied via ExecutionContext::$meta —
            // see PullPerfexCrmMeasurementsAction for the full rationale.
            'correlation_id' => $context->meta['correlation_id'] ?? null,
        ]);

        return ExecutionResult::ok(['measurement' => $fields + ['value' => $value]]);
    }
}
