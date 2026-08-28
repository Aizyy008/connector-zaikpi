<?php

namespace App\Services\RocketLms;

use App\Models\Connector;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for Rocket LMS's own API (confirmed: Sanctum + JWT, standard Bearer auth — see
 * project_2_v1_files/docs/03-rocket-lms-audit.md). Mirrors ZaiKpiClient's shape.
 *
 * Route group prefixes (`/api/instructor/...`, `/api/user/...`) follow the route-file naming
 * convention seen in routes/api/{instructor,user}.php but have NOT been independently confirmed
 * against a live response — verify before this adapter is considered done.
 */
class RocketLmsClient
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
            ->withToken($this->token)
            ->acceptJson();
    }

    /** Connection test used by the connector health check. */
    public function ping(): array
    {
        $r = $this->http()->get('user/quick-info');
        return ['ok' => $r->successful(), 'status' => $r->status(), 'body' => $r->json()];
    }

    /** Enrollments/purchases — RL-ENROLLMENTS, RL-ACTIVE-LEARNERS source. */
    public function purchases(array $query = []): array
    {
        return $this->result($this->http()->get('user/webinars/purchases', $query));
    }

    /** Order/sales history — RL-SALES source. */
    public function sales(array $query = []): array
    {
        return $this->result($this->http()->get('user/sales', $query));
    }

    /** Instructor course list — RL-VENDOR-ACTIVITY source. */
    public function instructorCourses(array $query = []): array
    {
        return $this->result($this->http()->get('instructor', $query));
    }

    /** Per-course completion statistic — RL-COURSE-COMPLETION source. */
    public function webinarStatistic(string $webinarId): array
    {
        return $this->result($this->http()->get("user/webinars/{$webinarId}/statistic"));
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
