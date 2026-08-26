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
