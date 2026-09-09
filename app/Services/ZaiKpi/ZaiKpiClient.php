<?php

namespace App\Services\ZaiKpi;

use App\Models\Connector;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Thin client for the ZaiKPI /api/v1 REST API. Reads its base URL from the
 * connector config and its bearer token from the encrypted `api_token`
 * credential — this is the ZaiKPI-specific glue the generic connector platform
 * needs (client Milestone 5), not new platform infrastructure.
 */
class ZaiKpiClient
{
    public function __construct(
        private string $baseUrl,
        private string $token,
        private int $timeout = 10,
    ) {}

    public static function forConnector(Connector $connector): self
    {
        $baseUrl = rtrim((string) ($connector->config['base_url'] ?? ''), '/');
        $token = optional($connector->credentials->firstWhere('key', 'api_token'))->value ?? '';

        return new self($baseUrl, $token, (int) ($connector->config['timeout'] ?? 10));
    }

    /**
     * `$correlationId`, when given, is sent as `X-Correlation-ID` — ZaiKPI's own
     * `CorrelationId` middleware reads that header (falling back to a fresh one only if absent),
     * so this is what actually completes the source → Connector → ZaiKPI trace (client-requested
     * fix, 2026-09-05 review — the measurement POST body itself has no correlation_id field,
     * confirmed from `KpiMeasurementController::store()`'s validation rules, so the header is the
     * real carrier, not a payload field).
     */
    private function http(?string $correlationId = null): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl . '/api/v1')
            ->timeout($this->timeout)
            ->withToken($this->token)
            ->acceptJson()
            ->asJson();

        return $correlationId ? $request->withHeaders(['X-Correlation-ID' => $correlationId]) : $request;
    }

    /** Connection test used by the connector health check. */
    public function ping(): array
    {
        $r = $this->http()->get('ping');
        return ['ok' => $r->successful(), 'status' => $r->status(), 'body' => $r->json()];
    }

    /** Inbound (FlinkISO → ZaiKPI): create/replace a KPI definition (idempotent by UUID). */
    public function pushKpiDefinition(array $payload, ?string $idempotencyKey = null): array
    {
        $r = $this->withIdem($idempotencyKey)->post('kpis', $payload);
        return $this->result($r);
    }

    /** Inbound: push a date-effective target for a KPI. */
    public function pushTarget(string $kpiUuid, array $payload, ?string $idempotencyKey = null): array
    {
        $r = $this->withIdem($idempotencyKey)->post("kpis/{$kpiUuid}/targets", $payload);
        return $this->result($r);
    }

    /**
     * Inbound (Project 2): find the ZaiKPI-side KPI definition for a source-aware adapter's
     * kpi_code, so its measurements can be posted to the right `kpis/{uuid}/measurements`.
     * Confirmed real filter support: `GET /api/v1/kpis?kpi_code=&kpi_namespace=&
     * source_application=` (KpiDefinitionController::index(), Project 1.b source-aware filters).
     * Returns the KPI's own `uuid`, or null if no matching definition exists yet in ZaiKPI —
     * per the requirements doc's Milestone 1 gate ("No KPI should be implemented without an
     * approved definition"), the caller must not silently create one on the fly.
     */
    public function findKpiUuid(string $kpiCode, string $kpiNamespace, string $sourceApplication, ?string $correlationId = null): ?string
    {
        $r = $this->http($correlationId)->get('kpis', [
            'kpi_code' => $kpiCode,
            'kpi_namespace' => $kpiNamespace,
            'source_application' => $sourceApplication,
            'per_page' => 1,
        ]);
        if (! $r->successful()) {
            return null;
        }
        $rows = $r->json('data') ?? [];

        return $rows[0]['uuid'] ?? null;
    }

    /**
     * Inbound (Project 2): post one aggregated measurement for a KPI already defined in ZaiKPI.
     * Real payload shape confirmed from `KpiMeasurementController::store()` — `measured_value`
     * is a single numeric (NOT the adapter's full breakdown object); `source_event_uuid` is the
     * DOMAIN-level idempotent-replay key (a repeated one returns the existing record instead of
     * duplicating) — this is what actually makes a replay safe, not the header below.
     *
     * Sends a FRESH `Idempotency-Key` on every call (client-corrected fix, 2026-09-09 3rd review:
     * "the already accepted Project 1.b contract... requires an Idempotency-Key on writes and
     * explicitly allows a fresh request-level Idempotency-Key when replaying the same
     * source_event_uuid"). Generated internally, never reused — this header exists to make one
     * literal HTTP retry of the SAME request safe (ZaiKPI's `Idempotency` middleware replays the
     * stored response if it sees the same key with the same body hash), which is a different job
     * from "is this logically the same measurement as one already recorded," which is what
     * `source_event_uuid` alone answers.
     *
     * History: 2026-09-05 found a real live bug and, at the time, the fix was to drop this header
     * entirely — root cause was reusing `source_event_uuid` itself AS the Idempotency-Key, so a
     * genuine replay (same key, but a body that legitimately differs because `measured_at` is
     * freshly generated) tripped the middleware's stricter hash-based conflict check before
     * `KpiMeasurementController::store()`'s own `source_event_uuid` guard ever got to run. That
     * diagnosis was correct; removing the header entirely was an over-correction — the header
     * itself is required by the accepted Project 1.b contract. The actual fix is this: never reuse
     * one key across separate requests. A fresh key every call means the middleware never sees a
     * repeated key with a different body, so the 2026-09-05 bug cannot recur, while the header the
     * contract requires is still sent.
     */
    public function pushMeasurement(string $kpiUuid, array $payload, ?string $correlationId = null): array
    {
        $r = $this->withIdem((string) Str::uuid(), $correlationId)->post("kpis/{$kpiUuid}/measurements", $payload);
        return $this->result($r);
    }

    /** Outbound (ZaiKPI → FlinkISO): list measurements for a KPI. */
    public function listMeasurements(string $kpiUuid, array $query = []): array
    {
        $r = $this->http()->get("kpis/{$kpiUuid}/measurements", $query);
        return $this->result($r);
    }

    /** Outbound: current status of a KPI. */
    public function status(string $kpiUuid): array
    {
        $r = $this->http()->get("kpis/{$kpiUuid}/status");
        return $this->result($r);
    }

    private function withIdem(?string $key, ?string $correlationId = null): PendingRequest
    {
        $req = $this->http($correlationId);
        return $key ? $req->withHeaders(['Idempotency-Key' => $key]) : $req;
    }

    private function result(\Illuminate\Http\Client\Response $response): array
    {
        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'data' => $response->json('data'),
            'error' => $response->successful() ? null : ($response->json('message') ?? 'request_failed'),
        ];
    }
}
