<?php

namespace App\Services\RocketLms;

use App\Models\Connector;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for Rocket LMS's mobile API. Mirrors ZaiKpiClient's shape.
 *
 * CONFIRMED live + from source, 2026-08-31 (see project_2_v1_files/docs/03-rocket-lms-{audit,
 * data-dictionary}.md §0 for the full trail):
 * - Auth is `tymon/jwt-auth`, NOT Sanctum. Guard `api` → model `App\Models\Api\User`.
 * - Real path prefix is `development/panel/...` (a literal, still-in-production leftover from
 *   the vendor's own routes/api.php — NOT `user/...` as an earlier draft of this client assumed).
 * - Every endpoint below is scoped to the authenticated user's OWN records — there is no
 *   admin/global view anywhere in this API (verified by reading the controller source). This
 *   client is therefore designed to be called with ONE CREDENTIAL PER VENDOR/TEACHER the client
 *   wants tracked, not one shared admin credential — see the data dictionary §0 for the 3 options
 *   this was chosen from.
 */
class RocketLmsClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $token,
        private int $timeout = 10,
    ) {}

    public static function forConnector(Connector $connector): self
    {
        $baseUrl = rtrim((string) ($connector->config['base_url'] ?? ''), '/');
        $apiKey = optional($connector->credentials->firstWhere('key', 'api_key'))->value ?? '';
        $token = optional($connector->credentials->firstWhere('key', 'api_token'))->value ?? '';

        return new self($baseUrl, $apiKey, $token, (int) ($connector->config['timeout'] ?? 10));
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl . '/api/development/panel')
            ->timeout($this->timeout)
            ->withToken($this->token)
            ->withHeaders(['x-api-key' => $this->apiKey])
            ->acceptJson();
    }

    /** Connection test used by the connector health check. */
    public function ping(): array
    {
        $r = $this->http()->get('quick-info');
        return ['ok' => $r->successful(), 'status' => $r->status(), 'body' => $r->json()];
    }

    /**
     * This vendor's own sales — RL-SALES, RL-REFUNDS, RL-ENROLLMENTS, RL-ACTIVE-LEARNERS source.
     * Confirmed live: `Sale::where('seller_id', $authUser->id)` — one vendor's own sales only.
     */
    public function sales(): array
    {
        return $this->result($this->http()->get('financial/sales'), 'sales');
    }

    /**
     * This vendor's own course list — RL-VENDOR-ACTIVITY source, and the id list used to pull
     * per-course completion below. Confirmed live: requires teacher/organization role
     * (`api.level-access:teacher`) — a non-teacher credential gets 403.
     */
    public function myClasses(): array
    {
        return $this->result($this->http()->get('classes'), 'my_classes');
    }

    /**
     * Per-course aggregate stats for one of this vendor's own courses — RL-COURSE-COMPLETION
     * source (`course_progress` field, confirmed from WebinarResource's statistic-mode block).
     * Confirmed live: only returns a course where creator_id/teacher_id = the calling user.
     */
    public function webinarStatistic(int $webinarId): array
    {
        $r = $this->http()->get("webinars/{$webinarId}/statistic");
        return [
            'ok' => $r->successful(),
            'status' => $r->status(),
            'data' => $r->json('data.webinar') ?? [],
            'error' => $r->successful() ? null : ($r->json('message') ?? 'request_failed'),
        ];
    }

    private function result(\Illuminate\Http\Client\Response $response, string $key): array
    {
        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'data' => $response->json("data.{$key}") ?? [],
            'error' => $response->successful() ? null : ($response->json('message') ?? 'request_failed'),
        ];
    }
}
