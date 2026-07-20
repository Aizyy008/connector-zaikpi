<?php

namespace App\Modules\ZaiKpi;

use App\Modules\AbstractModule;
use App\Modules\ExecutionContext;
use App\Modules\ExecutionResult;
use App\Modules\ModuleHealth;
use App\Services\ZaiKpi\ZaiKpiClient;

/**
 * Inbound (FlinkISO → ZaiKPI): transfers a KPI master-data definition into ZaiKPI
 * through its REST API. Idempotent on the canonical external UUID so re-runs never
 * duplicate. ZaiKPI-specific implementation only — the generic connector platform
 * (queue, mapping, audit) is reused unchanged.
 */
class PushKpiDefinitionAction extends AbstractModule
{
    public function slug(): string
    {
        return 'zaikpi.push_kpi_definition';
    }

    public function name(): string
    {
        return 'ZaiKPI · Push KPI Definition';
    }

    public function type(): string
    {
        return 'action';
    }

    public function description(): string
    {
        return 'Transfers a KPI definition (code, targets, units, ISO links, owners, thresholds) from FlinkISO into ZaiKPI.';
    }

    public function actions(): array
    {
        return ['push_kpi_definition'];
    }

    public function inputSchema(): array
    {
        return [
            'uuid' => 'string',
            'kpi_code' => 'string',
            'name' => 'string',
            'category' => 'string',
            'unit' => 'string',
            'direction_of_improvement' => 'string',
            'iso_links' => 'array',
        ];
    }

    public function outputSchema(): array
    {
        return ['uuid' => 'string', 'status' => 'string'];
    }

    public function scopes(): array
    {
        return ['flows.execute', 'kpis:write'];
    }

    public function healthCheck(): ModuleHealth
    {
        return ModuleHealth::Healthy;
    }

    public function execute(array $input, ExecutionContext $context): ExecutionResult
    {
        if (! $context->connector) {
            return ExecutionResult::fail('No ZaiKPI connector bound to this execution.');
        }

        $client = ZaiKpiClient::forConnector($context->connector);
        $idem = $input['uuid'] ?? ($context->meta['idempotency_key'] ?? null);

        $result = $client->pushKpiDefinition($input, $idem);

        if (! $result['ok']) {
            return ExecutionResult::fail($result['error'] ?? 'ZaiKPI push failed', ['status' => $result['status']]);
        }

        return ExecutionResult::ok([
            'uuid' => $result['data']['uuid'] ?? ($input['uuid'] ?? null),
            'status' => 'pushed',
        ]);
    }
}
