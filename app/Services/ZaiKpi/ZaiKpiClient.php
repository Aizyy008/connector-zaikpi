<?php

namespace App\Services\ZaiKpi;

use App\Models\Connector;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

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

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl . '/api/v1')
            ->timeout($this->timeout)
            ->withToken($this->token)
            ->acceptJson()
            ->asJson();
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
    public function findKpiUuid(string $kpiCode, string $kpiNamespace, string $sourceApplication): ?string
    {
        $r = $this->http()->get('kpis', [
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
     * idempotent-replay key (a repeated one returns the existing record instead of duplicating).
     */
    public function pushMeasurement(string $kpiUuid, array $payload, ?string $idempotencyKey = null): array
    {
        $r = $this->withIdem($idempotencyKey)->post("kpis/{$kpiUuid}/measurements", $payload);
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

    private function withIdem(?string $key): PendingRequest
    {
        $req = $this->http();
        return $key ? $req->withHeaders(['Idempotency-Key' => $key]) : $req;
    }

    private function result($response): array
    {
        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'data' => $response->json('data'),
            'error' => $response->successful() ? null : ($response->json('message') ?? 'request_failed'),
        ];
    }
}
