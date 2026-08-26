<?php

namespace App\Support\KpiAdapters;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Shared helper for Project 2's 5 KPI adapters (Rocket LMS, Perfex CRM, LeadHub,
 * Tour Guide, MiroTalk). Assembles the common adapter contract fields and the
 * standard outbound event envelope so every adapter builds these identically
 * instead of hand-rolling them per module — see
 * project_2_v1_files/docs/00-overview.md and 00-data-dictionary-template.md §4
 * for the field list this mirrors. This is plain additive application code,
 * not a change to ModuleContract/AbstractModule/ModuleRegistry or the webhook
 * intake pipeline — no core files are touched by this class existing.
 */
class AdapterEventEnvelope
{
    /**
     * Build the common adapter contract fields for an inbound payload (source
     * app → ZaiKPI). Pass whatever the source-specific mapping already
     * resolved; this fills in the fields every adapter must carry and applies
     * sane defaults for the ones that have one (received_at, correlation_id).
     *
     * @param array{
     *   tenant_uuid: string,
     *   source_application: string,
     *   source_module?: ?string,
     *   source_entity_type?: ?string,
     *   source_entity_uuid?: ?string,
     *   external_uuid: string,
     *   source_event_uuid?: ?string,
     *   correlation_id?: ?string,
     *   kpi_namespace: string,
     *   kpi_code: string,
     *   kpi_domain?: ?string,
     *   period_start?: ?string,
     *   period_end?: ?string,
     *   measured_at?: ?string,
     *   dimensions?: array<string, mixed>,
     * } $fields
     * @return array<string, mixed>
     */
    public static function contractFields(array $fields): array
    {
        foreach (['tenant_uuid', 'source_application', 'external_uuid', 'kpi_namespace', 'kpi_code'] as $required) {
            if (empty($fields[$required])) {
                throw new InvalidArgumentException(
                    "AdapterEventEnvelope::contractFields() missing required field '{$required}'."
                );
            }
        }

        return array_merge([
            'source_module' => null,
            'source_entity_type' => null,
            'source_entity_uuid' => null,
            'source_event_uuid' => null,
            'kpi_domain' => null,
            'period_start' => null,
            'period_end' => null,
            'measured_at' => null,
            'dimensions' => [],
        ], $fields, [
            'correlation_id' => $fields['correlation_id'] ?? (string) Str::uuid(),
            'received_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Build the standard outbound event envelope every adapter's EventTrigger
     * module should produce — mirrors ZaiKpiEventTrigger's output shape
     * (event_uuid, event_type, schema_version, tenant_uuid, source_application,
     * record_uuid, correlation_id, occurred_at, payload).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function eventEnvelope(
        string $eventType,
        string $tenantUuid,
        string $sourceApplication,
        ?string $recordUuid,
        array $payload,
        ?string $sourceModule = null,
        ?string $correlationId = null,
        string $schemaVersion = '1.0',
    ): array {
        return [
            'event_uuid' => (string) Str::uuid(),
            'event_type' => $eventType,
            'schema_version' => $schemaVersion,
            'tenant_uuid' => $tenantUuid,
            'source_application' => $sourceApplication,
            'source_module' => $sourceModule,
            'record_uuid' => $recordUuid,
            'correlation_id' => $correlationId ?? (string) Str::uuid(),
            'occurred_at' => now()->toIso8601String(),
            'payload' => $payload,
        ];
    }

    /**
     * Derive a stable idempotency key for a push/pull action, mirroring the
     * pattern already used by PushKpiDefinitionAction: prefer the record's
     * own identity (its uuid, or a source_event_uuid for measurements), then
     * fall back to whatever the caller supplied via ExecutionContext::$meta.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $meta
     */
    public static function idempotencyKey(array $input, array $meta): ?string
    {
        return $input['uuid'] ?? $input['source_event_uuid'] ?? $meta['idempotency_key'] ?? null;
    }
}
