<?php

namespace App\Modules\PerfexCrm;

use App\Modules\AbstractModule;
use App\Modules\ExecutionContext;
use App\Modules\ExecutionResult;
use App\Modules\ModuleHealth;
use App\Services\PerfexCrm\PerfexCrmClient;
use App\Support\KpiAdapters\AdapterEventEnvelope;
use App\Support\KpiAdapters\ZaiKpiDelivery;

/**
 * Perfex CRM KPI adapter (Project 2, requirements doc Milestone 3). Pulls one period's
 * aggregated measurement for an approved KPI from `modules/api` (see
 * project_2_v1_files/docs/04-perfex-crm-{audit,data-dictionary}.md — field names/status codes
 * are CONFIRMED from the live install's own source, not guessed), then delivers it to ZaiKPI —
 * a single execution performs the complete source → Connector → ZaiKPI flow (client-requested
 * fix, 2026-09-05 review), it no longer stops at "computed but not delivered."
 *
 * `external_uuid`/`source_event_uuid` are deterministic (same tenant/kpi/period → same value —
 * see `AdapterEventEnvelope::deterministicUuid()`), so re-running the same KPI for the same
 * period is a safe replay, not a duplicate measurement.
 *
 * Scope: read-only against the source, aggregated measurements only — no source-side code, no
 * raw record sync, matching this adapter's "pure Connector-side module" decision in the audit.
 */
class PullPerfexCrmMeasurementsAction extends AbstractModule
{
    /** Approved catalogue (04-perfex-crm-data-dictionary.md §2) — no KPI outside this list. */
    private const KPI_CODES = [
        'PX-LEADS', 'PX-LEAD-CONVERSION', 'PX-INVOICES', 'PX-COLLECTIONS',
        'PX-OUTSTANDING-BALANCE', 'PX-PROJECT-COMPLETION', 'PX-TASK-STATUS', 'PX-OVERDUE-WORK',
    ];

    public function slug(): string
    {
        return 'perfex_crm.pull_measurements';
    }

    public function name(): string
    {
        return 'Perfex CRM · Pull Measurements';
    }

    public function type(): string
    {
        return 'action';
    }

    public function description(): string
    {
        return 'Reads one aggregated KPI measurement (leads, invoices, payments, projects, tasks) from Perfex CRM for a period, and delivers it to ZaiKPI in one execution.';
    }

    public function actions(): array
    {
        return ['pull_measurements'];
    }

    public function inputSchema(): array
    {
        return [
            'kpi_code' => 'string',
            'tenant_uuid' => 'string',
            'period_start' => 'string',
            'period_end' => 'string',
        ];
    }

    public function outputSchema(): array
    {
        return ['measurement' => 'object', 'zaikpi_kpi_uuid' => 'string', 'zaikpi_measurement_uuid' => 'string'];
    }

    public function scopes(): array
    {
        return ['flows.execute', 'measurements:read', 'measurements:write'];
    }

    public function healthCheck(): ModuleHealth
    {
        return ModuleHealth::Healthy;
    }

    public function execute(array $input, ExecutionContext $context): ExecutionResult
    {
        if (! $context->connector) {
            return ExecutionResult::fail('No Perfex CRM connector bound to this execution.');
        }
        foreach (['kpi_code', 'tenant_uuid', 'period_start', 'period_end'] as $required) {
            if (empty($input[$required])) {
                return ExecutionResult::fail("{$required} is required.");
            }
        }
        if (! in_array($input['kpi_code'], self::KPI_CODES, true)) {
            return ExecutionResult::fail("kpi_code '{$input['kpi_code']}' is not in the approved Perfex CRM KPI catalogue.");
        }

        $startTs = AdapterEventEnvelope::periodStartTimestamp($input['period_start']);
        $endTs = AdapterEventEnvelope::periodEndTimestamp($input['period_end']);

        $client = PerfexCrmClient::forConnector($context->connector);
        $value = $this->compute($client, $input['kpi_code'], $startTs, $endTs);

        if ($value === null) {
            return ExecutionResult::fail("Failed to compute {$input['kpi_code']} — the Perfex CRM API call did not succeed.");
        }

        $fields = AdapterEventEnvelope::contractFields([
            'tenant_uuid' => $input['tenant_uuid'],
            'source_application' => 'perfex_crm',
            'source_entity_type' => 'kpi_measurement',
            'kpi_namespace' => 'perfex_crm.' . strtolower(str_replace('perfex_crm.', '', $this->domainFor($input['kpi_code']))),
            'kpi_code' => $input['kpi_code'],
            'kpi_domain' => $this->domainFor($input['kpi_code']),
            'period_start' => $input['period_start'],
            'period_end' => $input['period_end'],
            'measured_at' => now()->toIso8601String(),
            'correlation_id' => $context->meta['correlation_id'] ?? null,
        ]);

        // `primary_value` is the single number ZaiKpiDelivery sends as ZaiKPI's `measured_value`
        // (ZaiKPI tracks one scored number per KPI per period, not a breakdown object). Chosen
        // per the unit already documented in 04-perfex-crm-data-dictionary.md §2. PX-TASK-STATUS
        // is deliberately null — it's a status distribution, not a single trackable value; it's
        // still computed and returned for Connector-side visibility, just not pushed to ZaiKPI
        // (see 07-modification-register.md).
        $primaryValue = $this->primaryValueFor($input['kpi_code'], $value);
        $measurement = $fields + ['value' => $value, 'primary_value' => $primaryValue];

        if ($primaryValue === null) {
            return ExecutionResult::ok(['measurement' => $measurement, 'zaikpi_push_skipped' => 'no single trackable value for this KPI']);
        }

        $delivery = ZaiKpiDelivery::deliver($context, $measurement);
        if (! $delivery['ok']) {
            return ExecutionResult::fail(
                "Computed {$input['kpi_code']} but failed to deliver it to ZaiKPI: {$delivery['error']}",
                ['measurement' => $measurement],
            );
        }

        return ExecutionResult::ok([
            'measurement' => $measurement,
            'zaikpi_kpi_uuid' => $delivery['zaikpi_kpi_uuid'],
            'zaikpi_measurement_uuid' => $delivery['zaikpi_measurement_uuid'],
        ]);
    }

    private function primaryValueFor(string $kpiCode, array $value): ?float
    {
        $key = match ($kpiCode) {
            'PX-LEADS', 'PX-LEAD-CONVERSION', 'PX-OVERDUE-WORK' => 'count',
            'PX-INVOICES', 'PX-COLLECTIONS', 'PX-OUTSTANDING-BALANCE' => 'sum',
            'PX-PROJECT-COMPLETION' => 'rate',
            'PX-TASK-STATUS' => null,
            default => null,
        };

        return $key !== null && isset($value[$key]) ? (float) $value[$key] : null;
    }

    /** Domain per 04-perfex-crm-data-dictionary.md §1. */
    private function domainFor(string $kpiCode): string
    {
        return match (true) {
            str_starts_with($kpiCode, 'PX-LEAD') => 'crm',
            in_array($kpiCode, ['PX-INVOICES', 'PX-COLLECTIONS', 'PX-OUTSTANDING-BALANCE'], true) => 'finance',
            default => 'projects',
        };
    }

    /**
     * Field names and status codes CONFIRMED 2026-08-31 from the live install's own source
     * (application/models/{Invoices_model,Tasks_model}.php status constants, and
     * Projects_model::get_project_statuses() cross-referenced against
     * language/english/english_lang.php) — not guesses. See 04-perfex-crm-audit.md.
     *
     * Invoice status: 1=Unpaid, 2=Paid, 3=Partially paid (Invoices_model::STATUS_*).
     * Task status: 1=Not started, 2=Awaiting feedback, 3=Testing, 4=In progress, 5=Complete
     * (Tasks_model::STATUS_*).
     * Project status: 1..5, id 4 = "Finished" (language key project_status_4).
     *
     * PX-LEAD-CONVERSION resolved 2026-08-31 per the client's own definition ("converted when
     * moved into a Customer through Perfex's standard lead conversion process") — read from
     * `application/controllers/admin/Leads.php::convert_to_customer()`: the real conversion
     * action does `UPDATE leads SET date_converted = NOW(), ...` on the lead row itself.
     * `Leads_model::get()` selects `*` from `leads`, so `date_converted` is already present in
     * every `GET /api/leads` response — no new endpoint, reuses countInPeriod() exactly like
     * PX-LEADS (a null `date_converted` is simply unparseable/absent and excluded).
     *
     * Date comparisons use real Unix timestamps (via AdapterEventEnvelope's helpers), not raw
     * string comparison — fixed 2026-09-05 per client review: comparing Perfex's date strings
     * (some bare dates, some with a time component) lexically could wrongly exclude records on
     * the period's own end date.
     */
    private const INVOICE_STATUS_PAID = 2;
    private const TASK_STATUS_COMPLETE = 5;
    private const PROJECT_STATUS_FINISHED = 4;

    private function compute(PerfexCrmClient $client, string $kpiCode, int $startTs, int $endTs): ?array
    {
        return match ($kpiCode) {
            'PX-LEADS' => $this->countInPeriod($client, 'leads', 'dateadded', $startTs, $endTs),
            'PX-LEAD-CONVERSION' => $this->countInPeriod($client, 'leads', 'date_converted', $startTs, $endTs),
            'PX-INVOICES' => $this->countAndSum($client, 'invoices', 'date', 'total', $startTs, $endTs),
            'PX-COLLECTIONS' => $this->countAndSum($client, 'payments', 'date', 'amount', $startTs, $endTs),
            'PX-OUTSTANDING-BALANCE' => $this->sumWhere($client, 'invoices', fn ($r) => (int) ($r['status'] ?? 0) !== self::INVOICE_STATUS_PAID, 'total'),
            'PX-PROJECT-COMPLETION' => $this->rate($client, 'projects', null, $startTs, $endTs, fn ($r) => (int) ($r['status'] ?? 0) === self::PROJECT_STATUS_FINISHED),
            'PX-TASK-STATUS' => $this->breakdown($client, 'tasks', 'status'),
            'PX-OVERDUE-WORK' => $this->countWhere($client, 'tasks', fn ($r) => ! empty($r['duedate']) && (strtotime($r['duedate']) ?: PHP_INT_MAX) < $endTs && (int) ($r['status'] ?? 0) !== self::TASK_STATUS_COMPLETE),
            default => null,
        };
    }

    private function countInPeriod(PerfexCrmClient $client, string $resource, string $dateField, int $startTs, int $endTs): ?array
    {
        $result = $client->list($resource);
        if (! $result['ok']) {
            return null;
        }
        $rows = collect($result['data'])->filter(fn ($r) => AdapterEventEnvelope::timestampInRange($r[$dateField] ?? null, $startTs, $endTs));

        return ['count' => $rows->count()];
    }

    private function countAndSum(PerfexCrmClient $client, string $resource, string $dateField, string $sumField, int $startTs, int $endTs): ?array
    {
        $result = $client->list($resource);
        if (! $result['ok']) {
            return null;
        }
        $rows = collect($result['data'])->filter(fn ($r) => AdapterEventEnvelope::timestampInRange($r[$dateField] ?? null, $startTs, $endTs));

        return ['count' => $rows->count(), 'sum' => (float) $rows->sum($sumField)];
    }

    private function sumWhere(PerfexCrmClient $client, string $resource, callable $predicate, string $sumField): ?array
    {
        $result = $client->list($resource);
        if (! $result['ok']) {
            return null;
        }

        return ['sum' => (float) collect($result['data'])->filter($predicate)->sum($sumField)];
    }

    private function countWhere(PerfexCrmClient $client, string $resource, callable $predicate): ?array
    {
        $result = $client->list($resource);
        if (! $result['ok']) {
            return null;
        }

        return ['count' => collect($result['data'])->filter($predicate)->count()];
    }

    private function rate(PerfexCrmClient $client, string $resource, ?string $dateField, int $startTs, int $endTs, callable $matchPredicate): ?array
    {
        $result = $client->list($resource);
        if (! $result['ok']) {
            return null;
        }
        $rows = collect($result['data']);
        if ($dateField) {
            $rows = $rows->filter(fn ($r) => AdapterEventEnvelope::timestampInRange($r[$dateField] ?? null, $startTs, $endTs));
        }
        $total = $rows->count();

        return ['total' => $total, 'matched' => $rows->filter($matchPredicate)->count(), 'rate' => $total > 0 ? round($rows->filter($matchPredicate)->count() / $total, 4) : null];
    }

    private function breakdown(PerfexCrmClient $client, string $resource, string $statusField): ?array
    {
        $result = $client->list($resource);
        if (! $result['ok']) {
            return null;
        }

        return collect($result['data'])->countBy($statusField)->all();
    }
}
