<?php

namespace App\Services\LeadHub;

use App\Models\Connector;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for LeadHub's REST API. Mirrors the shape of the other Project 2 clients.
 *
 * CONFIRMED live 2026-09-03 (see project_2_v1_files/docs/09-leadhub-{audit,data-dictionary}.md):
 * - Auth is `Authorization: Bearer lh_<key>` — a real custom API-key scheme, not Sanctum/JWT.
 * - Real response shape: `{data: [...], meta: {current_page, last_page, per_page, total, from,
 *   to}, links: {...}}` — genuinely its own shape among this project's adapters.
 * - `GET /api/v1/leads` supports real server-side `created_after`/`created_before` filters —
 *   used directly instead of pulling everything and filtering client-side.
 */
class LeadHubClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private int $timeout = 10,
    ) {}

    public static function forConnector(Connector $connector): self
    {
        $baseUrl = rtrim((string) ($connector->config['base_url'] ?? ''), '/');
        $apiKey = optional($connector->credentials->firstWhere('key', 'api_key'))->value ?? '';

        return new self($baseUrl, $apiKey, (int) ($connector->config['timeout'] ?? 10));
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl . '/api/v1')
            ->timeout($this->timeout)
            ->withToken($this->apiKey)
            ->acceptJson();
    }

    /** Connection test used by the connector health check. */
    public function ping(): array
    {
        $r = $this->http()->get('leads', ['per_page' => 1]);
        return ['ok' => $r->successful(), 'status' => $r->status(), 'body' => $r->json()];
    }

    /**
     * Leads created within a period (server-side filtered) — source for LH-NEW-LEADS,
     * LH-RESPONSE-TIME, LH-STAGE-CONVERSION. Auto-paginates (real pagination confirmed live).
     */
    public function leadsCreatedInPeriod(string $start, string $end): array
    {
        return $this->paginatedLeads(['created_after' => $start, 'created_before' => $end]);
    }

    /**
     * Leads currently in a given status — source for LH-QUALIFIED-LEADS, LH-WON-LOST. The
     * caller filters by `updated_at` afterwards (best available proxy — see data dictionary §0,
     * the real per-status-change event log has no REST endpoint).
     */
    public function leadsByStatus(string $status): array
    {
        return $this->paginatedLeads(['status' => $status]);
    }

    /** Stages for one pipeline — source for LH-STAGE-CONVERSION's `is_won` lookup. */
    public function pipelineStages(int $pipelineId): array
    {
        $r = $this->http()->get("pipelines/{$pipelineId}/stages");
        return [
            'ok' => $r->successful(),
            'status' => $r->status(),
            'data' => $r->json('data') ?? [],
            'error' => $r->successful() ? null : ($r->json('message') ?? 'request_failed'),
        ];
    }

    /** All pipelines — used to enumerate pipelines for the stage lookup above. */
    public function pipelines(): array
    {
        $r = $this->http()->get('pipelines');
        return [
            'ok' => $r->successful(),
            'status' => $r->status(),
            'data' => $r->json('data') ?? [],
            'error' => $r->successful() ? null : ($r->json('message') ?? 'request_failed'),
        ];
    }

    private function paginatedLeads(array $query): array
    {
        $all = [];
        $page = 1;
        do {
            $r = $this->http()->get('leads', $query + ['page' => $page, 'per_page' => 100]);
            if (! $r->successful()) {
                return ['ok' => false, 'status' => $r->status(), 'data' => [], 'error' => $r->json('message') ?? 'request_failed'];
            }
            $all = array_merge($all, $r->json('data') ?? []);
            $lastPage = $r->json('meta.last_page') ?? 1;
            $page++;
        } while ($page <= $lastPage);

        return ['ok' => true, 'status' => 200, 'data' => $all, 'error' => null];
    }
}
