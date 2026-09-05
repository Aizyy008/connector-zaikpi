<?php

namespace App\Support\KpiAdapters;

use App\Models\Connector;
use App\Modules\ExecutionContext;
use App\Services\ZaiKpi\ZaiKpiClient;

/**
 * Completes "source → Connector → ZaiKPI" from a single module execution (client-requested fix,
 * 2026-09-05 review: "The current implementation does not appear to provide a complete automatic
 * Pull → Push orchestration... Please make sure that a normal execution actually performs the
 * complete source → Connector → ZaiKPI flow without requiring the Pull and Push actions to be
 * executed separately or manually.").
 *
 * Each of the 5 `Pull*MeasurementsAction` modules calls `ZaiKpiDelivery::deliver()` itself, right
 * after computing its measurement, instead of stopping and leaving delivery to a second, manual
 * step. `PushMeasurementToZaiKpiAction` (kept registered for standalone/manual re-delivery, e.g.
 * re-pushing a measurement that was computed earlier) delegates to the same method, so there is
 * exactly one implementation of "find the KPI, build the payload, push it" — not five copies.
 *
 * The ZaiKPI connector is resolved from the SOURCE execution's own workspace (one ZaiKPI
 * connector per workspace, `provider = 'zaikpi'` — matches how this workspace is actually set
 * up), since a source adapter's `ExecutionContext` only carries its own source connector, not a
 * second one for ZaiKPI.
 */
class ZaiKpiDelivery
{
    /**
     * @param array<string, mixed> $measurement A contract-shaped measurement — see
     *   `AdapterEventEnvelope::contractFields()` — with `value` and `primary_value` added.
     * @return array{ok: bool, error?: string, zaikpi_kpi_uuid?: ?string, zaikpi_measurement_uuid?: ?string}
     */
    public static function deliver(ExecutionContext $context, array $measurement): array
    {
        foreach (['kpi_code', 'kpi_namespace', 'source_application', 'period_start', 'period_end', 'external_uuid'] as $required) {
            if (empty($measurement[$required])) {
                return ['ok' => false, 'error' => "measurement.{$required} is required."];
            }
        }
        if (! isset($measurement['primary_value']) || ! is_numeric($measurement['primary_value'])) {
            return [
                'ok' => false,
                'error' => "measurement.primary_value is required and must be numeric — kpi_code '{$measurement['kpi_code']}' either doesn't reduce to a single trackable number (e.g. a status breakdown) or the source adapter didn't set one.",
            ];
        }

        // A Pull*MeasurementsAction's own $context->connector is its SOURCE connector, not
        // ZaiKPI's — resolve ZaiKPI separately by workspace. PushMeasurementToZaiKpiAction's
        // standalone/manual use attaches the ZaiKPI connector directly to $context->connector,
        // so honor that when it's already the right one.
        $zaikpiConnector = ($context->connector && $context->connector->provider === 'zaikpi')
            ? $context->connector
            : Connector::where('workspace_id', $context->workspace->id)->where('provider', 'zaikpi')->first();
        if (! $zaikpiConnector) {
            return ['ok' => false, 'error' => 'No ZaiKPI connector configured for this workspace.'];
        }

        $client = ZaiKpiClient::forConnector($zaikpiConnector);
        $correlationId = $measurement['correlation_id'] ?? null;

        $kpiUuid = $client->findKpiUuid(
            $measurement['kpi_code'],
            $measurement['kpi_namespace'],
            $measurement['source_application'],
            $correlationId,
        );
        if ($kpiUuid === null) {
            return [
                'ok' => false,
                'error' => "No approved KPI definition found in ZaiKPI for kpi_code '{$measurement['kpi_code']}' in namespace '{$measurement['kpi_namespace']}' — per Milestone 1, a KPI definition must be approved in ZaiKPI before its measurements can be pushed.",
            ];
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
        $result = $client->pushMeasurement($kpiUuid, $payload, $idem, $correlationId);

        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?? 'ZaiKPI measurement push failed'];
        }

        return [
            'ok' => true,
            'zaikpi_kpi_uuid' => $kpiUuid,
            'zaikpi_measurement_uuid' => $result['data']['uuid'] ?? null,
        ];
    }
}
