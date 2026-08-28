<?php

namespace App\Modules\PerfexCrm;

use App\Modules\AbstractModule;
use App\Modules\ExecutionContext;
use App\Modules\ExecutionResult;
use App\Modules\ModuleHealth;
use App\Services\PerfexCrm\PerfexCrmClient;
use App\Support\KpiAdapters\AdapterEventEnvelope;
use Illuminate\Support\Str;

/**
 * Perfex CRM KPI adapter (Project 2, requirements doc Milestone 3). Pulls one period's
 * aggregated measurement for an approved KPI from `modules/api` (see
 * project_2_v1_files/docs/04-perfex-crm-{audit,data-dictionary}.md — every field name below
 * is BEST-EFFORT from Perfex CRM's known public schema, NOT yet verified against a live
 * response from this specific install; verify against one real authenticated call before
 * this adapter is considered done, per the requirements doc's "no endpoint/field assumed
 * without verifying in staging" rule).
 *
 * Scope: read-only, aggregated measurements only — no source-side code, no raw record sync,
 * matching this adapter's "pure Connector-side module" decision in the audit doc.
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
        return 'Reads one aggregated KPI measurement (leads, invoices, payments, projects, tasks) from Perfex CRM for a period, for delivery to ZaiKPI.';
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
        return ['measurement' => 'object'];
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

        $client = PerfexCrmClient::forConnector($context->connector);
        $value = $this->compute($client, $input['kpi_code'], $input['period_start'], $input['period_end']);

        if ($value === null) {
            return ExecutionResult::fail("Failed to compute {$input['kpi_code']} — the Perfex CRM API call did not succeed.");
        }

        $fields = AdapterEventEnvelope::contractFields([
            'tenant_uuid' => $input['tenant_uuid'],
            'source_application' => 'perfex_crm',
            'source_entity_type' => 'kpi_measurement',
            'external_uuid' => (string) Str::uuid(),
            'kpi_namespace' => 'perfex_crm.' . strtolower(str_replace('perfex_crm.', '', $this->domainFor($input['kpi_code']))),
            'kpi_code' => $input['kpi_code'],
            'kpi_domain' => $this->domainFor($input['kpi_code']),
            'period_start' => $input['period_start'],
            'period_end' => $input['period_end'],
            'measured_at' => now()->toIso8601String(),
        ]);

        return ExecutionResult::ok(['measurement' => $fields + ['value' => $value]]);
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
     * Field names are best-effort from Perfex CRM's public schema (id/status/total/dateadded
     * conventions) — see the class docblock. Returns null on any API failure; never guesses a
     * value from a failed call.
     */
    private function compute(PerfexCrmClient $client, string $kpiCode, string $start, string $end): ?array
    {
        return match ($kpiCode) {
            'PX-LEADS' => $this->countInPeriod($client, 'leads', 'dateadded', $start, $end),
            'PX-LEAD-CONVERSION' => $this->rate($client, 'leads', 'dateadded', $start, $end, fn ($r) => ($r['status'] ?? null) === 'converted'),
            'PX-INVOICES' => $this->countAndSum($client, 'invoices', 'date', 'total', $start, $end),
            'PX-COLLECTIONS' => $this->countAndSum($client, 'payments', 'date', 'amount', $start, $end),
            'PX-OUTSTANDING-BALANCE' => $this->sumWhere($client, 'invoices', fn ($r) => (($r['status'] ?? null) !== 'paid'), 'total'),
            'PX-PROJECT-COMPLETION' => $this->rate($client, 'projects', null, $start, $end, fn ($r) => ($r['status'] ?? null) === 'finished'),
            'PX-TASK-STATUS' => $this->breakdown($client, 'tasks', 'status'),
            'PX-OVERDUE-WORK' => $this->countWhere($client, 'tasks', fn ($r) => ! empty($r['duedate']) && $r['duedate'] < $end && ($r['status'] ?? null) !== 'complete'),
            default => null,
        };
    }

    private function countInPeriod(PerfexCrmClient $client, string $resource, string $dateField, string $start, string $end): ?array
    {
        $result = $client->list($resource);
        if (! $result['ok']) {
            return null;
        }
        $rows = collect($result['data'])->filter(fn ($r) => isset($r[$dateField]) && $r[$dateField] >= $start && $r[$dateField] <= $end);

        return ['count' => $rows->count()];
    }

    private function countAndSum(PerfexCrmClient $client, string $resource, string $dateField, string $sumField, string $start, string $end): ?array
    {
        $result = $client->list($resource);
        if (! $result['ok']) {
            return null;
        }
        $rows = collect($result['data'])->filter(fn ($r) => isset($r[$dateField]) && $r[$dateField] >= $start && $r[$dateField] <= $end);

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

    private function rate(PerfexCrmClient $client, string $resource, ?string $dateField, string $start, string $end, callable $matchPredicate): ?array
    {
        $result = $client->list($resource);
        if (! $result['ok']) {
            return null;
        }
        $rows = collect($result['data']);
        if ($dateField) {
            $rows = $rows->filter(fn ($r) => isset($r[$dateField]) && $r[$dateField] >= $start && $r[$dateField] <= $end);
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
