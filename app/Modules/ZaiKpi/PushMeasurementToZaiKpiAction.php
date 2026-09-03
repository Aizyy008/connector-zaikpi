<?php

namespace App\Modules\ZaiKpi;

use App\Modules\AbstractModule;
use App\Modules\ExecutionContext;
use App\Modules\ExecutionResult;
use App\Modules\ModuleHealth;
use App\Services\ZaiKpi\ZaiKpiClient;

/**
 * Inbound (Project 2's 5 KPI adapters → ZaiKPI): the missing second half of "source → Connector
 * → ZaiKPI". A `Pull*MeasurementsAction` (Perfex CRM/Rocket LMS/Tour Guide/MiroTalk/LeadHub)
 * only reads from its source and returns a measurement — it doesn't call ZaiKPI itself, mirroring
 * `PushKpiDefinitionAction`'s existing pattern of one module per external system rather than one
 * module juggling two. This module takes that measurement (already in the common KPI contract
 * shape — see `AdapterEventEnvelope::contractFields()`) and delivers it to ZaiKPI's real
 * `POST /kpis/{uuid}/measurements` (confirmed from `KpiMeasurementController::store()`, not
 * guessed). Generic across all 5 sources — the contract shape is source-agnostic by design.
 *
 * Requires the target KPI to already exist in ZaiKPI (found via `kpi_code`+`kpi_namespace`+
 * `source_application`) — per the requirements doc's Milestone 1 gate ("No KPI should be
 * implemented without an approved definition"), this module never creates one on the fly.
 */
class PushMeasurementToZaiKpiAction extends AbstractModule
{
    public function slug(): string
    {
        return 'zaikpi.push_measurement';
    }

    public function name(): string
    {
        return 'ZaiKPI · Push Measurement';
    }

    public function type(): string
    {
        return 'action';
    }

    public function description(): string
    {
        return 'Delivers one already-computed KPI measurement (from any of the 5 Project 2 source adapters) into ZaiKPI.';
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
        foreach (['kpi_code', 'kpi_namespace', 'source_application', 'period_start', 'period_end', 'external_uuid'] as $required) {
            if (empty($measurement[$required])) {
                return ExecutionResult::fail("measurement.{$required} is required.");
            }
        }
        if (! isset($measurement['primary_value']) || ! is_numeric($measurement['primary_value'])) {
            return ExecutionResult::fail(
                "measurement.primary_value is required and must be numeric — kpi_code '{$measurement['kpi_code']}' either doesn't reduce to a single trackable number (e.g. a status breakdown) or the source adapter didn't set one."
            );
        }

        $client = ZaiKpiClient::forConnector($context->connector);

        $kpiUuid = $client->findKpiUuid($measurement['kpi_code'], $measurement['kpi_namespace'], $measurement['source_application']);
        if ($kpiUuid === null) {
            return ExecutionResult::fail(
                "No approved KPI definition found in ZaiKPI for kpi_code '{$measurement['kpi_code']}' in namespace '{$measurement['kpi_namespace']}' — per Milestone 1, a KPI definition must be approved in ZaiKPI before its measurements can be pushed."
            );
        }

        $payload = [
            'uuid' => $measurement['external_uuid'],
            'period_start' => $measurement['period_start'],
            'period_end' => $measurement['period_end'],
            'measured_value' => (float) $measurement['primary_value'],
            'measurement_source' => $measurement['source_application'],
            'notes' => isset($measurement['value']) ? json_encode($measurement['value']) : null,
            'source_event_uuid' => $measurement['source_event_uuid'] ?? $measurement['external_uuid'],
            'measured_at' => $measurement['measured_at'] ?? null,
        ];

        $idem = $measurement['source_event_uuid'] ?? $measurement['external_uuid'];
        $result = $client->pushMeasurement($kpiUuid, $payload, $idem);

        if (! $result['ok']) {
            return ExecutionResult::fail($result['error'] ?? 'ZaiKPI measurement push failed', ['status' => $result['status']]);
        }

        return ExecutionResult::ok([
            'zaikpi_kpi_uuid' => $kpiUuid,
            'zaikpi_measurement_uuid' => $result['data']['uuid'] ?? null,
        ]);
    }
}
