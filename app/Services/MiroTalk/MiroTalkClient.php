<?php

namespace App\Services\MiroTalk;

use App\Models\Connector;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for MiroTalk SFU's REST API. Mirrors the shape of the other Project 2 clients.
 *
 * CONFIRMED live 2026-09-03 (see project_2_v1_files/docs/05-mirotalk-{audit,data-dictionary}.md):
 * - Auth is a single shared secret sent as the raw `authorization` header value — NOT
 *   `Bearer <token>`, NOT JWT. Public default (`mirotalksfu_default_secret`) confirmed rejected;
 *   this deployment runs a real custom secret.
 * - `GET /api/v1/stats` is the only usable endpoint — `GET /api/v1/meetings` is confirmed
 *   disabled on this deployment ("This endpoint has been disabled...").
 */
class MiroTalkClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKeySecret,
        private int $timeout = 10,
    ) {}

    public static function forConnector(Connector $connector): self
    {
        $baseUrl = rtrim((string) ($connector->config['base_url'] ?? ''), '/');
        $apiKeySecret = optional($connector->credentials->firstWhere('key', 'api_key_secret'))->value ?? '';

        return new self($baseUrl, $apiKeySecret, (int) ($connector->config['timeout'] ?? 10));
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl . '/api/v1')
            ->timeout($this->timeout)
            ->withHeaders(['authorization' => $this->apiKeySecret])
            ->acceptJson();
    }

    /** Connection test used by the connector health check. */
    public function ping(): array
    {
        $r = $this->http()->get('stats');
        return ['ok' => $r->successful(), 'status' => $r->status(), 'body' => $r->json()];
    }

    /**
     * Live snapshot — MT-ACTIVE-ROOMS/MT-ACTIVE-USERS source. Real field is `totalUsers`
     * (swagger.yaml documents `totalPeers`, which the live response does NOT actually return).
     */
    public function stats(): array
    {
        $r = $this->http()->get('stats');
        return [
            'ok' => $r->successful(),
            'status' => $r->status(),
            'data' => $r->json() ?? [],
            'error' => $r->successful() ? null : ($r->json('error') ?? 'request_failed'),
        ];
    }
}
