<?php

namespace App\Modules\Builtin;

use App\Modules\AbstractModule;

/**
 * Trigger fired when an inbound webhook payload is accepted (wired up in M4/M5).
 */
class WebhookReceivedTrigger extends AbstractModule
{
    public function slug(): string
    {
        return 'core.webhook_received';
    }

    public function name(): string
    {
        return 'Webhook Received';
    }

    public function type(): string
    {
        return 'trigger';
    }

    public function description(): string
    {
        return 'Starts a flow when a validated webhook payload arrives for a connector.';
    }

    public function executionMethod(): string
    {
        return 'webhook';
    }

    public function actions(): array
    {
        return ['emit_event'];
    }

    public function outputSchema(): array
    {
        return [
            'event_type' => 'string',
            'connector' => 'string',
            'payload' => 'object',
        ];
    }

    public function scopes(): array
    {
        return ['webhooks.view'];
    }
}
