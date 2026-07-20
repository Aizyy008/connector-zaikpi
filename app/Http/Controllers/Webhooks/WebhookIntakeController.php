<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\WebhookEndpoint;
use App\Models\WebhookPayload;
use App\Services\FlowRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Public, unauthenticated intake for external webhooks. Resolves the endpoint by
 * slug, verifies the HMAC signature (when a secret is set), validates the JSON,
 * and always records a payload log with a clear status — invalid payloads are
 * stored, not dropped.
 */
class WebhookIntakeController extends Controller
{
    private const SENSITIVE_HEADERS = ['authorization', 'cookie', 'x-xsrf-token'];

    public function store(Request $request, string $slug): JsonResponse
    {
        $endpoint = WebhookEndpoint::withoutWorkspaceScope()->where('slug', $slug)->first();

        if (! $endpoint || ! $endpoint->enabled) {
            return response()->json(['error' => 'Unknown or disabled endpoint.'], 404);
        }

        $raw = $request->getContent();
        $headers = $this->safeHeaders($request);

        // 1. Signature verification (when configured).
        if ($endpoint->hasSecret() && ! $this->signatureValid($endpoint, $raw, $request)) {
            $payload = $this->record($endpoint, $raw, $headers, 'invalid', 'Signature verification failed.');

            return response()->json(['error' => 'Invalid signature.', 'id' => $payload->id], 401);
        }

        // 2. JSON validation.
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            $payload = $this->record($endpoint, $raw, $headers, 'invalid', 'Malformed or non-object JSON body.');

            return response()->json(['error' => 'Invalid JSON payload.', 'id' => $payload->id], 422);
        }

        // 3. Basic completeness check.
        if ($decoded === []) {
            $payload = $this->record($endpoint, $raw, $headers, 'invalid', 'Empty payload.', $decoded);

            return response()->json(['error' => 'Empty payload.', 'id' => $payload->id], 422);
        }

        // 4. Accepted. Trigger matching automation flows -> queued execution jobs.
        $payload = $this->record($endpoint, $raw, $headers, 'valid', null, $decoded);

        try {
            app(FlowRunner::class)->handlePayload($payload);
        } catch (Throwable $e) {
            // Flow dispatch failures must not break intake; the payload is already logged.
            report($e);
        }

        return response()->json(['status' => 'accepted', 'id' => $payload->id], 202);
    }

    private function signatureValid(WebhookEndpoint $endpoint, string $raw, Request $request): bool
    {
        $provided = (string) $request->header($endpoint->signature_header, '');
        if ($provided === '') {
            return false;
        }

        $expected = hash_hmac($endpoint->signature_algo, $raw, (string) $endpoint->secret);

        return hash_equals($expected, $provided);
    }

    private function record(WebhookEndpoint $endpoint, string $raw, array $headers, string $status, ?string $error = null, ?array $decoded = null): WebhookPayload
    {
        $payload = WebhookPayload::create([
            'workspace_id' => $endpoint->workspace_id,
            'connector_id' => $endpoint->connector_id,
            'endpoint_id' => $endpoint->id,
            'headers' => $headers,
            'raw_payload' => $raw,
            'parsed_payload' => $decoded,
            'status' => $status,
            'error' => $error,
            'received_at' => now(),
        ]);

        AuditLog::record('webhook.received', $payload, ['status' => $status], $endpoint->workspace_id);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function safeHeaders(Request $request): array
    {
        return collect($request->headers->all())
            ->reject(fn ($v, $k) => in_array(strtolower($k), self::SENSITIVE_HEADERS, true))
            ->map(fn ($v) => is_array($v) && count($v) === 1 ? $v[0] : $v)
            ->all();
    }
}
