<?php

namespace App\Services\PerfexCrm;

use App\Models\Connector;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the "REST API for Perfex CRM" add-on module (confirmed installed at
 * `modules/api` on the client's Perfex instance — see
 * project_2_v1_files/docs/04-perfex-crm-audit.md). Mirrors ZaiKpiClient's shape.
 *
 * Auth is a pre-issued JWT sent via a custom `authtoken` header — NOT `Authorization: Bearer`.
 * The token itself is generated through Perfex's own admin UI (Setup -> Staff -> API tab); the
 * module's own `POST /api/login/auth` endpoint is unmodified vendor template code with no real
 * credential check, so it must never be used to obtain a token.
 */
class PerfexCrmClient
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
        return Http::baseUrl($this->baseUrl . '/api')
            ->timeout($this->timeout)
            ->withHeaders(['authtoken' => $this->token])
            ->acceptJson();
    }

    /** Connection test used by the connector health check. */
    public function ping(): array
    {
        $r = $this->http()->get('staffs');
        return ['ok' => $r->successful(), 'status' => $r->status(), 'body' => $r->json()];
    }

    /**
     * List a resource with an optional date range. Every KPI in the approved data dictionary
     * (04-perfex-crm-data-dictionary.md) is computed from one of these lists — no per-KPI
     * bespoke endpoint, matching how modules/api/config/routes.php maps
     * GET /api/{controller}[/{id}] uniformly for every controller.
     *
     * @param 'leads'|'invoices'|'payments'|'projects'|'tasks'|'credit_notes' $resource
     */
    public function list(string $resource, array $query = []): array
    {
        $r = $this->http()->get($resource, $query);
        return $this->result($r);
    }

    private function result($response): array
    {
        // Confirmed live behavior (not a guess): this REST API returns HTTP 404 with
        // {"status":false,"message":"No data were found"} for a genuinely empty list — not an
        // error. Treat that specific shape as a successful empty result; any other non-2xx is a
        // real failure.
        $isEmptyNotFound = $response->status() === 404 && $response->json('message') === 'No data were found';

        return [
            'ok' => $response->successful() || $isEmptyNotFound,
            'status' => $response->status(),
            'data' => $isEmptyNotFound ? [] : ($response->json('result') ?? $response->json() ?? []),
            'error' => ($response->successful() || $isEmptyNotFound) ? null : ($response->json('error') ?? 'request_failed'),
        ];
    }
}
