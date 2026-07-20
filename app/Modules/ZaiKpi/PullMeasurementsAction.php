<?php

namespace App\Modules\ZaiKpi;

use App\Modules\AbstractModule;
use App\Modules\ExecutionContext;
use App\Modules\ExecutionResult;
use App\Modules\ModuleHealth;
use App\Services\ZaiKpi\ZaiKpiClient;

/**
 * Outbound (ZaiKPI → FlinkISO): pulls operational KPI data (measurements, scores,
 * approval status, threshold flags) from ZaiKPI for a KPI so the platform can
 * forward it to FlinkISO Expansion.
 */
class PullMeasurementsAction extends AbstractModule
{
    public function slug(): string
    {
        return 'zaikpi.pull_measurements';
    }

    public function name(): string
    {
        return 'ZaiKPI · Pull Measurements';
    }

    public function type(): string
    {
        return 'action';
    }

    public function description(): string
    {
        return 'Reads KPI measurements, scores and approval status from ZaiKPI for delivery to FlinkISO.';
    }

    public function actions(): array
    {
        return ['pull_measurements'];
    }

    public function inputSchema(): array
    {
        return ['kpi_uuid' => 'string', 'approval_status' => 'string'];
    }

    public function outputSchema(): array
    {
        return ['count' => 'number', 'measurements' => 'array'];
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
            return ExecutionResult::fail('No ZaiKPI connector bound to this execution.');
        }
        if (empty($input['kpi_uuid'])) {
            return ExecutionResult::fail('kpi_uuid is required.');
        }

        $client = ZaiKpiClient::forConnector($context->connector);
        $query = array_filter(['approval_status' => $input['approval_status'] ?? null]);

        $result = $client->listMeasurements($input['kpi_uuid'], $query);

        if (! $result['ok']) {
            return ExecutionResult::fail($result['error'] ?? 'ZaiKPI pull failed', ['status' => $result['status']]);
        }

        $measurements = $result['data'] ?? [];

        return ExecutionResult::ok([
            'count' => count($measurements),
            'measurements' => $measurements,
        ]);
    }
}
