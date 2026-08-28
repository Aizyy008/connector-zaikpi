<?php

namespace App\Services\TourGuide;

use App\Models\Connector;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Tour Guide (Usertour) REST API. Mirrors ZaiKpiClient's shape.
 *
 * Confirmed 2026-08-29: genuinely self-hosted (Usertour v0.9.0, Docker), Bearer/JWT auth per its
 * OpenAPI spec, no webhook capability anywhere in its deployment docs — this is a poll-only
 * adapter (no EventTrigger module). See project_2_v1_files/docs/01-tour-guide-audit.md.
 *
 * Pagination/date-filter query params on GET /v1/content-sessions are NOT confirmed against a
 * live response yet (no token available) — verify before this adapter is considered done.
 */
class TourGuideClient
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
        return Http::baseUrl($this->baseUrl . '/v1')
            ->timeout($this->timeout)
            ->withToken($this->token)
            ->acceptJson();
    }

    /** Connection test used by the connector health check. */
    public function ping(): array
    {
        $r = $this->http()->get('companies', ['limit' => 1]);
        return ['ok' => $r->successful(), 'status' => $r->status(), 'body' => $r->json()];
    }

    /**
     * List content (tour/flow) sessions for a period — the single source pull that
     * TG-GUIDE-STARTS, TG-GUIDE-COMPLETIONS, TG-COMPLETION-RATE and TG-FEATURE-ADOPTION are all
     * aggregated from.
     */
    public function listContentSessions(array $query = []): array
    {
        $r = $this->http()->get('content-sessions', $query);
        return $this->result($r);
    }

    private function result($response): array
    {
        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'data' => $response->json('data') ?? $response->json() ?? [],
            'error' => $response->successful() ? null : ($response->json('message') ?? 'request_failed'),
        ];
    }
}
