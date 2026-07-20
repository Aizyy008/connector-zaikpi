<?php

namespace App\Services;

use App\Models\Connector;

/**
 * Runs a connector health check and records the result. The MVP evaluates
 * credential presence and expiry (no live external calls yet); real provider
 * pings can be added per connector type without changing callers.
 */
class ConnectorTester
{
    /**
     * @return array{status:string, message:string}
     */
    public function test(Connector $connector): array
    {
        $credentials = $connector->credentials;

        [$status, $message] = match (true) {
            $credentials->isEmpty() => ['disconnected', 'No credentials configured.'],
            $credentials->contains(fn ($c) => $c->isExpired()) => ['warning', 'One or more credentials have expired.'],
            ! $connector->enabled => ['warning', 'Connector is disabled.'],
            default => ['healthy', 'Credentials present and valid.'],
        };

        $connector->forceFill([
            'status' => $status,
            'last_health_status' => $message,
            'health_checked_at' => now(),
        ])->save();

        return ['status' => $status, 'message' => $message];
    }
}
