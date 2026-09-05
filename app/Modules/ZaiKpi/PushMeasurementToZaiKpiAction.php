<?php

namespace App\Modules\ZaiKpi;

use App\Modules\AbstractModule;
use App\Modules\ExecutionContext;
use App\Modules\ExecutionResult;
use App\Modules\ModuleHealth;
use App\Support\KpiAdapters\ZaiKpiDelivery;

/**
 * Standalone/manual re-delivery of an already-computed measurement into ZaiKPI. As of the
 * 2026-09-05 review, each of the 5 `Pull*MeasurementsAction` modules delivers its own
 * measurement automatically (via the same `ZaiKpiDelivery::deliver()` this module calls) — a
 * normal execution no longer needs this module run as a separate manual step. This module stays
 * registered for the case where a measurement needs re-pushing without re-pulling from the
 * source (e.g. after fixing a ZaiKPI-side definition problem).
 */
class PushMeasurementToZaiKpiAction extends AbstractModule
{
    public function slug(): string
    {
        return 'zaikpi.push_measurement';
    }

    public function name(): string
    {
        return 'ZaiKPI · Push Measurement (manual re-delivery)';
    }

    public function type(): string
    {
        return 'action';
    }

    public function description(): string
    {
        return 'Manually (re-)delivers one already-computed KPI measurement into ZaiKPI — not needed for normal operation, since every Pull*MeasurementsAction now delivers automatically.';
    }

    public function actions(): array
    {
        return ['push_measurement'];
    }

    public function inputSchema(): array
    {
        return ['measurement' => 'object'];
    }

    public function outputSchema(): array
    {
        return ['zaikpi_kpi_uuid' => 'string', 'zaikpi_measurement_uuid' => 'string'];
    }

    public function scopes(): array
    {
        return ['flows.execute', 'measurements:write'];
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
        $measurement = $input['measurement'] ?? null;
        if (! is_array($measurement)) {
            return ExecutionResult::fail('measurement is required.');
        }

        $delivery = ZaiKpiDelivery::deliver($context, $measurement);
        if (! $delivery['ok']) {
            return ExecutionResult::fail($delivery['error']);
        }

        return ExecutionResult::ok([
            'zaikpi_kpi_uuid' => $delivery['zaikpi_kpi_uuid'],
            'zaikpi_measurement_uuid' => $delivery['zaikpi_measurement_uuid'],
        ]);
    }
}
