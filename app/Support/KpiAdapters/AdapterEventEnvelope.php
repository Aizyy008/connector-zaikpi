<?php

namespace App\Support\KpiAdapters;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

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
     * Fixed namespace used to derive stable event identities (see deterministicUuid()) —
     * arbitrary but must never change once anything has been pushed to ZaiKPI, or every
     * existing measurement's replay key would shift. Just the well-known RFC 4122 example DNS
     * namespace UUID; its meaning doesn't matter, only that it's constant.
     */
    private const DETERMINISTIC_UUID_NAMESPACE = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    /**
     * Build the common adapter contract fields for an inbound payload (source
     * app → ZaiKPI). Pass whatever the source-specific mapping already
     * resolved; this fills in the fields every adapter must carry and applies
     * sane defaults for the ones that have one (received_at, correlation_id).
     *
     * `external_uuid`/`source_event_uuid` are now DETERMINISTIC by default (client-requested fix,
     * 2026-09-05 review: "The adapters currently generate a new external_uuid/event identifier
     * on each execution... Please implement a stable/deterministic source_event_uuid or
     * equivalent replay key"). Re-running the same kpi_code for the same tenant/source/namespace/
     * period now produces the SAME uuid every time, so ZaiKPI's own replay guard
     * (`KpiMeasurementController::store()`'s `source_event_uuid` match) recognizes a re-run
     * instead of creating a duplicate measurement. Pass an explicit `external_uuid` only when a
     * genuinely distinct per-record identity is needed (not the case for any of the 5 adapters
     * as built — every one is a period aggregate, not a per-record event).
     *
     * @param array{
     *   tenant_uuid: string,
     *   source_application: string,
     *   source_module?: ?string,
     *   source_entity_type?: ?string,
     *   source_entity_uuid?: ?string,
     *   external_uuid?: ?string,
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
        foreach (['tenant_uuid', 'source_application', 'kpi_namespace', 'kpi_code'] as $required) {
            if (empty($fields[$required])) {
                throw new InvalidArgumentException(
                    "AdapterEventEnvelope::contractFields() missing required field '{$required}'."
                );
            }
        }

        $deterministicUuid = self::deterministicUuid(
            $fields['tenant_uuid'],
            $fields['source_application'],
            $fields['kpi_namespace'],
            $fields['kpi_code'],
            $fields['period_start'] ?? '',
            $fields['period_end'] ?? '',
        );
        $externalUuid = $fields['external_uuid'] ?? $deterministicUuid;

        return array_merge([
            'source_module' => null,
            'source_entity_type' => null,
            'source_entity_uuid' => null,
            'kpi_domain' => null,
            'period_start' => null,
            'period_end' => null,
            'measured_at' => null,
            'dimensions' => [],
        ], $fields, [
            'external_uuid' => $externalUuid,
            'source_event_uuid' => $fields['source_event_uuid'] ?? $externalUuid,
            'correlation_id' => $fields['correlation_id'] ?? (string) Str::uuid(),
            'received_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Derive a stable (tenant, source, namespace, kpi_code, period) identity as a UUID —
     * name-based (v5), so the SAME inputs always produce the SAME uuid, never a random one.
     * This is what makes re-running the same KPI/period a safe replay instead of a duplicate.
     */
    public static function deterministicUuid(
        string $tenantUuid,
        string $sourceApplication,
        string $kpiNamespace,
        string $kpiCode,
        string $periodStart,
        string $periodEnd,
    ): string {
        $name = implode('|', [$tenantUuid, $sourceApplication, $kpiNamespace, $kpiCode, $periodStart, $periodEnd]);

        return Uuid::uuid5(self::DETERMINISTIC_UUID_NAMESPACE, $name)->toString();
    }

    /**
     * Parse period_start into a Unix timestamp. Client-requested fix (2026-09-05 review):
     * "normalize date/time comparisons... so records occurring on the end date are not
     * accidentally excluded because they include a time component" — this and
     * periodEndTimestamp()/timestampInRange() replace every adapter's previous raw string
     * comparison (`$record[$field] >= $start`) with real timestamp comparison.
     */
    public static function periodStartTimestamp(string $periodStart): int
    {
        $ts = strtotime($periodStart);
        if ($ts === false) {
            throw new InvalidArgumentException("Unparseable period_start: '{$periodStart}'.");
        }

        return $ts;
    }

    /**
     * Parse period_end into a Unix timestamp. A bare date (no time component, e.g. '2026-08-31')
     * is extended to the END of that day (23:59:59) — otherwise it means exact midnight, which
     * would exclude nearly the entire day it names. A caller who supplies an explicit time
     * (e.g. '2026-08-31T23:59:59Z') is respected as-is.
     */
    public static function periodEndTimestamp(string $periodEnd): int
    {
        $ts = strtotime($periodEnd);
        if ($ts === false) {
            throw new InvalidArgumentException("Unparseable period_end: '{$periodEnd}'.");
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($periodEnd)) === 1) {
            $ts += 86399; // 23:59:59 past midnight
        }

        return $ts;
    }

    /**
     * True if $value (a date/datetime string, or a Unix timestamp already) falls within
     * [$startTs, $endTs] inclusive. Use this instead of comparing raw strings — see
     * periodEndTimestamp()'s docblock for why raw string comparison is unsafe.
     */
    public static function timestampInRange(mixed $value, int $startTs, int $endTs): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
        if ($ts === false) {
            return false;
        }

        return $ts >= $startTs && $ts <= $endTs;
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
