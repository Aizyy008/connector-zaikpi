<?php

namespace Tests\Unit;

use App\Support\KpiAdapters\AdapterEventEnvelope;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests the shared contract/envelope helper used by all 5 Project 2 adapters,
 * in isolation — no database needed, this class does no Eloquent/DB work.
 * Adapter-specific tests (once built) should NOT re-test this logic; they
 * should only assert their own module/client wiring calls it correctly.
 */
class KpiAdapterContractTest extends TestCase
{
    public function test_contract_fields_requires_the_mandatory_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdapterEventEnvelope::contractFields([
            'tenant_uuid' => 'tenant-A',
            // missing source_application, external_uuid, kpi_namespace, kpi_code
        ]);
    }

    public function test_contract_fields_fills_defaults_and_carries_input_through(): void
    {
        $fields = AdapterEventEnvelope::contractFields([
            'tenant_uuid' => 'tenant-A',
            'source_application' => 'rocket_lms',
            'external_uuid' => 'ext-123',
            'kpi_namespace' => 'rocket_lms.learning',
            'kpi_code' => 'COURSE-COMPLETIONS',
        ]);

        $this->assertSame('tenant-A', $fields['tenant_uuid']);
        $this->assertSame('rocket_lms', $fields['source_application']);
        $this->assertSame('rocket_lms.learning', $fields['kpi_namespace']);
        $this->assertNull($fields['source_module']);
        $this->assertSame([], $fields['dimensions']);
        $this->assertNotEmpty($fields['correlation_id']);
        $this->assertNotEmpty($fields['received_at']);
    }

    public function test_contract_fields_preserves_a_caller_supplied_correlation_id(): void
    {
        $fields = AdapterEventEnvelope::contractFields([
            'tenant_uuid' => 'tenant-A',
            'source_application' => 'rocket_lms',
            'external_uuid' => 'ext-123',
            'kpi_namespace' => 'rocket_lms.learning',
            'kpi_code' => 'COURSE-COMPLETIONS',
            'correlation_id' => 'corr-fixed-1',
        ]);

        $this->assertSame('corr-fixed-1', $fields['correlation_id']);
    }

    public function test_event_envelope_has_every_standard_field(): void
    {
        $envelope = AdapterEventEnvelope::eventEnvelope(
            eventType: 'measurement.recorded',
            tenantUuid: 'tenant-A',
            sourceApplication: 'rocket_lms',
            recordUuid: 'record-123',
            payload: ['value' => 42],
        );

        foreach ([
            'event_uuid', 'event_type', 'schema_version', 'tenant_uuid',
            'source_application', 'source_module', 'record_uuid',
            'correlation_id', 'occurred_at', 'payload',
        ] as $key) {
            $this->assertArrayHasKey($key, $envelope);
        }
        $this->assertSame('measurement.recorded', $envelope['event_type']);
        $this->assertSame(['value' => 42], $envelope['payload']);
    }

    /**
     * Client-flagged fix, 2026-09-09 review: "the deterministic source_event_uuid... does not
     * include source_entity_uuid/source_entity_type. This can cause two different vendors/
     * entities under the same tenant, with the same KPI and period, to generate the same replay
     * key." Direct, fast, no-database proof against the actual pure function — see
     * Project2AdapterExecutionTest's Rocket LMS test for the same property proven end-to-end
     * through a real module execution.
     */
    public function test_deterministic_uuid_is_entity_aware(): void
    {
        $sameEntityFirst = AdapterEventEnvelope::deterministicUuid(
            'tenant-A', 'rocket_lms', 'rocket_lms.marketplace', 'RL-SALES', '2026-08-01', '2026-08-31', 'vendor', 'vendor-101'
        );
        $sameEntitySecond = AdapterEventEnvelope::deterministicUuid(
            'tenant-A', 'rocket_lms', 'rocket_lms.marketplace', 'RL-SALES', '2026-08-01', '2026-08-31', 'vendor', 'vendor-101'
        );
        $differentEntity = AdapterEventEnvelope::deterministicUuid(
            'tenant-A', 'rocket_lms', 'rocket_lms.marketplace', 'RL-SALES', '2026-08-01', '2026-08-31', 'vendor', 'vendor-202'
        );
        $noEntity = AdapterEventEnvelope::deterministicUuid(
            'tenant-A', 'rocket_lms', 'rocket_lms.marketplace', 'RL-SALES', '2026-08-01', '2026-08-31'
        );

        $this->assertSame($sameEntityFirst, $sameEntitySecond, 'Same entity + same KPI + same period must produce the same replay key.');
        $this->assertNotSame($sameEntityFirst, $differentEntity, 'Different entity + same KPI + same period must produce a different replay key.');
        $this->assertNotSame($sameEntityFirst, $noEntity, 'An entity-scoped key must differ from the same call with no entity at all.');
    }

    public function test_idempotency_key_prefers_uuid_then_source_event_uuid_then_meta(): void
    {
        $this->assertSame(
            'uuid-1',
            AdapterEventEnvelope::idempotencyKey(
                ['uuid' => 'uuid-1', 'source_event_uuid' => 'evt-1'],
                ['idempotency_key' => 'meta-1']
            )
        );

        $this->assertSame(
            'evt-1',
            AdapterEventEnvelope::idempotencyKey(
                ['source_event_uuid' => 'evt-1'],
                ['idempotency_key' => 'meta-1']
            )
        );

        $this->assertSame(
            'meta-1',
            AdapterEventEnvelope::idempotencyKey([], ['idempotency_key' => 'meta-1'])
        );

        $this->assertNull(AdapterEventEnvelope::idempotencyKey([], []));
    }
}
