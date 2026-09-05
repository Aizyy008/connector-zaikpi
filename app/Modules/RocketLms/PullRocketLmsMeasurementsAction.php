<?php

namespace App\Modules\RocketLms;

use App\Modules\AbstractModule;
use App\Modules\ExecutionContext;
use App\Modules\ExecutionResult;
use App\Modules\ModuleHealth;
use App\Services\RocketLms\RocketLmsClient;
use App\Support\KpiAdapters\AdapterEventEnvelope;
use App\Support\KpiAdapters\ZaiKpiDelivery;

/**
 * Rocket LMS KPI adapter (Project 2, requirements doc Milestone 4). Pulls one period's
 * aggregated measurement for an approved KPI, per VENDOR (see
 * project_2_v1_files/docs/03-rocket-lms-{audit,data-dictionary}.md §0), then delivers it to
 * ZaiKPI — a single execution performs the complete source → Connector → ZaiKPI flow
 * (client-requested fix, 2026-09-05 review).
 *
 * CONFIRMED live + from source, 2026-08-31: Rocket LMS's mobile API has no admin/global view —
 * every endpoint only returns the authenticated user's own records. This adapter is therefore
 * designed to run once PER CONNECTED VENDOR CREDENTIAL (one Connector = one vendor/teacher
 * login), not once for the whole platform. `tenant_uuid` stays the client's tenant; the vendor
 * identity travels in `source_entity_uuid` so ZaiKPI can tell one vendor's numbers apart from
 * another's. Per the client's 2026-09-05 confirmation, any existing test instructor/vendor
 * account is fine for now — production accounts are picked later at rollout.
 *
 * All 7 KPIs below trace to a real, confirmed field or model — nothing guessed. `RL-SUBSCRIPTIONS`
 * was resolved by reading `SubscribesController`/`Sale.php` source (2026-08-31): Rocket LMS's
 * "subscribe" feature is a course-access plan, not recurring billing — redeeming it against a
 * course creates a normal `sales` row with `payment_method === 'subscribe'` (`Sale::$subscribe`),
 * so it's computed from the SAME `financial/sales` pull as RL-SALES/RL-REFUNDS, no new endpoint.
 * The `installment` folder (a separate, unrelated "pay for one course in instalments" feature)
 * was checked and confirmed NOT to be the subscription mechanism.
 *
 * `external_uuid`/`source_event_uuid` are deterministic (see AdapterEventEnvelope), so re-running
 * the same KPI/period is a safe replay, not a duplicate measurement.
 */
class PullRocketLmsMeasurementsAction extends AbstractModule
{
    private const KPI_CODES = [
        'RL-SALES', 'RL-REFUNDS', 'RL-ENROLLMENTS', 'RL-SUBSCRIPTIONS',
        'RL-ACTIVE-LEARNERS', 'RL-VENDOR-ACTIVITY', 'RL-COURSE-COMPLETION',
    ];

    /** Sale types counted as an enrollment (excludes bookings/meetings). */
    private const ENROLLMENT_SALE_TYPES = ['webinar', 'bundle'];

    public function slug(): string
    {
        return 'rocket_lms.pull_measurements';
    }

    public function name(): string
    {
        return 'Rocket LMS · Pull Measurements';
    }

    public function type(): string
    {
        return 'action';
    }

    public function description(): string
    {
        return 'Reads one aggregated KPI measurement (sales, refunds, enrollments, active learners, course activity/completion) for one connected Rocket LMS vendor, and delivers it to ZaiKPI in one execution.';
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
            return ExecutionResult::fail('No Rocket LMS connector bound to this execution.');
        }
        foreach (['kpi_code', 'tenant_uuid', 'period_start', 'period_end'] as $required) {
            if (empty($input[$required])) {
                return ExecutionResult::fail("{$required} is required.");
            }
        }
        if (! in_array($input['kpi_code'], self::KPI_CODES, true)) {
            return ExecutionResult::fail("kpi_code '{$input['kpi_code']}' is not in the approved Rocket LMS KPI catalogue (or its source is unconfirmed — see 03-rocket-lms-data-dictionary.md).");
        }

        // Real Unix timestamps, bare-date period_end extended to end-of-day — fixes a real bug
        // (client-flagged 2026-09-05): the old strtotime() with no end-of-day extension meant a
        // bare-date period_end effectively meant "midnight," excluding nearly the whole day.
        $startTs = AdapterEventEnvelope::periodStartTimestamp($input['period_start']);
        $endTs = AdapterEventEnvelope::periodEndTimestamp($input['period_end']);

        $client = RocketLmsClient::forConnector($context->connector);
        $value = $this->compute($client, $input['kpi_code'], $startTs, $endTs);

        if ($value === null) {
            return ExecutionResult::fail("Failed to compute {$input['kpi_code']} — the Rocket LMS API call did not succeed.");
        }

        $vendorId = $context->connector->config['vendor_user_id'] ?? null;

        $fields = AdapterEventEnvelope::contractFields([
            'tenant_uuid' => $input['tenant_uuid'],
            'source_application' => 'rocket_lms',
            'source_entity_type' => 'vendor',
            'source_entity_uuid' => $vendorId !== null ? (string) $vendorId : null,
            'kpi_namespace' => 'rocket_lms.' . $this->domainFor($input['kpi_code']),
            'kpi_code' => $input['kpi_code'],
            'kpi_domain' => $this->domainFor($input['kpi_code']),
            'period_start' => $input['period_start'],
            'period_end' => $input['period_end'],
            'measured_at' => now()->toIso8601String(),
            'correlation_id' => $context->meta['correlation_id'] ?? null,
        ]);

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
            'RL-SALES', 'RL-REFUNDS' => 'sum',
            'RL-ENROLLMENTS', 'RL-SUBSCRIPTIONS', 'RL-ACTIVE-LEARNERS', 'RL-VENDOR-ACTIVITY' => 'count',
            'RL-COURSE-COMPLETION' => 'average_progress',
            default => null,
        };

        return $key !== null && isset($value[$key]) ? (float) $value[$key] : null;
    }

    /** Domain per 03-rocket-lms-data-dictionary.md §1. */
    private function domainFor(string $kpiCode): string
    {
        return match ($kpiCode) {
            'RL-VENDOR-ACTIVITY', 'RL-SALES', 'RL-REFUNDS' => 'marketplace',
            'RL-SUBSCRIPTIONS' => 'subscription',
            default => 'learning',
        };
    }

    /**
     * Field names CONFIRMED 2026-08-31 from live authenticated responses (see the data
     * dictionary §2) — not guesses. `created_at`/`refund_at` on sale rows are UNIX timestamps
     * already, compared directly against $startTs/$endTs (also Unix timestamps, via
     * AdapterEventEnvelope's helpers — fixes the bare-date-period_end bug noted above).
     *
     * RL-ENROLLMENTS/RL-ACTIVE-LEARNERS/RL-COURSE-COMPLETION reconciled against the data
     * dictionary 2026-09-05 (client review caught the dictionary text describing an OLDER,
     * pre-08-31 approach — `webinars/purchases` + `progress_percent` — that was superseded when
     * the adapter was corrected to the real per-vendor-scoped `financial/sales`/`classes`
     * endpoints; the CODE here was already right, the dictionary text just hadn't caught up —
     * see 03-rocket-lms-data-dictionary.md, now updated to match).
     */
    private function compute(RocketLmsClient $client, string $kpiCode, int $startTs, int $endTs): ?array
    {
        return match ($kpiCode) {
            'RL-SALES' => $this->salesCountAndSum($client, $startTs, $endTs, fn () => true),
            'RL-REFUNDS' => $this->salesCountAndSum($client, $startTs, $endTs, fn ($r) => ! empty($r['refund_at'])),
            'RL-ENROLLMENTS' => $this->salesCountAndSum(
                $client, $startTs, $endTs,
                fn ($r) => in_array($r['type'] ?? null, self::ENROLLMENT_SALE_TYPES, true)
            ),
            'RL-SUBSCRIPTIONS' => $this->salesCountAndSum($client, $startTs, $endTs, fn ($r) => ($r['payment_method'] ?? null) === 'subscribe'),
            'RL-ACTIVE-LEARNERS' => $this->activeLearners($client, $startTs, $endTs),
            'RL-VENDOR-ACTIVITY' => $this->vendorActivity($client, $startTs, $endTs),
            'RL-COURSE-COMPLETION' => $this->courseCompletion($client),
            default => null,
        };
    }

    private function salesRowsInPeriod(RocketLmsClient $client, int $startTs, int $endTs): ?\Illuminate\Support\Collection
    {
        $result = $client->sales();
        if (! $result['ok']) {
            return null;
        }

        return collect($result['data'])->filter(fn ($r) => AdapterEventEnvelope::timestampInRange($r['created_at'] ?? null, $startTs, $endTs));
    }

    private function salesCountAndSum(RocketLmsClient $client, int $startTs, int $endTs, callable $predicate): ?array
    {
        $rows = $this->salesRowsInPeriod($client, $startTs, $endTs);
        if ($rows === null) {
            return null;
        }
        $matched = $rows->filter($predicate);

        return ['count' => $matched->count(), 'sum' => (float) $matched->sum(fn ($r) => (float) ($r['total_amount'] ?? $r['amount'] ?? 0))];
    }

    /** `buyer_id` confirmed present on live `financial/sales` rows — no longer "needs verification". */
    private function activeLearners(RocketLmsClient $client, int $startTs, int $endTs): ?array
    {
        $rows = $this->salesRowsInPeriod($client, $startTs, $endTs);
        if ($rows === null) {
            return null;
        }

        return ['count' => $rows->pluck('buyer_id')->filter()->unique()->count()];
    }

    private function vendorActivity(RocketLmsClient $client, int $startTs, int $endTs): ?array
    {
        $result = $client->myClasses();
        if (! $result['ok']) {
            return null;
        }
        $rows = collect($result['data'])->filter(fn ($r) => AdapterEventEnvelope::timestampInRange($r['created_at'] ?? null, $startTs, $endTs));

        return ['count' => $rows->count()];
    }

    /**
     * Average `course_progress` (confirmed field, WebinarResource statistic-mode block) across
     * this vendor's own courses. One statistic call per course — acceptable for a per-vendor
     * course list, which is expected to be small. Not period-filtered — this is a live snapshot
     * of each course's current completion, matching what `course_progress` actually represents
     * (there's no historical per-period value for it in the source API).
     */
    private function courseCompletion(RocketLmsClient $client): ?array
    {
        $classes = $client->myClasses();
        if (! $classes['ok']) {
            return null;
        }
        $ids = collect($classes['data'])->pluck('id')->filter();
        if ($ids->isEmpty()) {
            return ['courses' => 0, 'average_progress' => null];
        }

        $progresses = $ids->map(function ($id) use ($client) {
            $stat = $client->webinarStatistic((int) $id);
            return $stat['ok'] ? ($stat['data']['course_progress'] ?? null) : null;
        })->filter(fn ($p) => is_numeric($p));

        return [
            'courses' => $ids->count(),
            'average_progress' => $progresses->isEmpty() ? null : round($progresses->avg(), 2),
        ];
    }
}
