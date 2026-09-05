<?php

namespace App\Services\TourGuide;

use App\Models\Connector;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Tour Guide (Usertour) REST API. Mirrors ZaiKpiClient's shape.
 *
 * CONFIRMED live 2026-09-03 (real API key, see project_2_v1_files/docs/02-tour-guide-data-
 * dictionary.md): Bearer auth confirmed working, base path `/v1`. List responses use
 * `{results: [...], next, previous}` — NOT `{data: [...]}` as originally guessed.
 * `GET /v1/content-sessions` REQUIRES a `contentId` query param (confirmed live —
 * `{"error":{"code":"E1017","message":"contentId should not be empty"}}` without one) — it is
 * NOT a global pull like the original draft of this client assumed. This client therefore lists
 * `content` first, then the action pulls sessions per content id.
 *
 * Follows `next` through every page (client-flagged bug, 2026-09-05 review: only the first page
 * was being read, silently dropping every KPI's later results whenever a list spans more than
 * one page).
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

    /** All content (guides/tours/checklists), across every page. */
    public function listContent(): array
    {
        return $this->paginatedResult('content', []);
    }

    /**
     * Sessions for ONE content item, across every page — TG-GUIDE-STARTS, TG-GUIDE-COMPLETIONS,
     * TG-COMPLETION-RATE and TG-FEATURE-ADOPTION are all aggregated from this, across every
     * content id (confirmed live: this endpoint requires contentId, it's not a global pull).
     */
    public function listContentSessions(string $contentId): array
    {
        return $this->paginatedResult('content-sessions', ['contentId' => $contentId]);
    }

    /**
     * Follows the real `next` cursor link (a full relative path, e.g.
     * `/v1/content-sessions?cursor=...&limit=20`) until it's null, merging every page's
     * `results`. A safety cap (50 pages) prevents an infinite loop if the API ever misbehaves.
     */
    private function paginatedResult(string $path, array $query): array
    {
        $all = [];
        $r = $this->http()->get($path, $query);
        if (! $r->successful()) {
            return ['ok' => false, 'status' => $r->status(), 'data' => [], 'error' => $r->json('message') ?? 'request_failed'];
        }
        $all = array_merge($all, $r->json('results') ?? []);
        $next = $r->json('next');

        $pages = 0;
        while ($next && $pages < 50) {
            $r = Http::baseUrl($this->baseUrl)->timeout($this->timeout)->withToken($this->token)->acceptJson()->get($next);
            if (! $r->successful()) {
                return ['ok' => false, 'status' => $r->status(), 'data' => $all, 'error' => $r->json('message') ?? 'request_failed'];
            }
            $all = array_merge($all, $r->json('results') ?? []);
            $next = $r->json('next');
            $pages++;
        }

        return ['ok' => true, 'status' => 200, 'data' => $all, 'error' => null];
    }
}
