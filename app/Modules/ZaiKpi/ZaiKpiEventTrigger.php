<?php

namespace App\Modules\ZaiKpi;

use App\Modules\AbstractModule;

/**
 * Trigger for ZaiKPI outbound events delivered to the connector webhook intake:
 * measurement.recorded, threshold.breached, approval.decided, kpi.created/updated.
 * The generic intake verifies the HMAC-SHA256 signature ZaiKPI sends
 * (X-ZaiKPI-Signature); this trigger declares the events and normalized output a
 * flow can forward to FlinkISO.
 */
class ZaiKpiEventTrigger extends AbstractModule
{
    /** Events emitted by the ZaiKPI API (see OutboundEventEmitter). */
    public const EVENTS = [
        'kpi.created',
        'kpi.updated',
        'measurement.recorded',
        'threshold.breached',
        'approval.decided',
    ];

    public function slug(): string
    {
        return 'zaikpi.event_received';
    }

    public function name(): string
    {
        return 'ZaiKPI · Event Received';
    }

    public function type(): string
    {
        return 'trigger';
    }

    public function description(): string
    {
        return 'Starts a flow when ZaiKPI delivers an operational event (measurement, threshold breach, approval).';
    }

    public function executionMethod(): string
    {
        return 'webhook';
    }

    public function actions(): array
    {
        return ['emit_event'];
    }

    public function outputSchema(): array
    {
        return [
            'event' => 'string',
            'tenant_id' => 'string',
            'entity_type' => 'string',
            'entity_uuid' => 'string',
            'occurred_at' => 'string',
            'data' => 'object',
        ];
    }

    public function scopes(): array
    {
        return ['webhooks.view'];
    }
}
