<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Connector;
use App\Models\ConnectorCredential;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Credentials are encrypted at rest and never rendered in plaintext. Editing uses
 * a leave-blank-to-keep pattern so the secret is not round-tripped through the UI.
 */
class ConnectorCredentialController extends Controller
{
    public const TYPES = ['bearer', 'hmac', 'oauth', 'basic', 'custom'];

    public function __construct(private readonly WorkspaceContext $context) {}

    public function store(Request $request, Connector $connector): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:80', Rule::unique('connector_credentials', 'key')->where('connector_id', $connector->id)],
            'type' => ['required', Rule::in(self::TYPES)],
            'value' => ['required', 'string'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $credential = new ConnectorCredential([
            'connector_id' => $connector->id,
            'key' => $data['key'],
            'type' => $data['type'],
            'expires_at' => $data['expires_at'] ?? null,
        ]);
        $credential->setSecret($data['value']);
        $credential->save();

        AuditLog::record('connector.credential.created', $connector, ['key' => $credential->key], $this->context->id());

        return back()->with('status', "Credential “{$credential->key}” saved.");
    }

    public function update(Request $request, Connector $connector, ConnectorCredential $credential): RedirectResponse
    {
        abort_unless($credential->connector_id === $connector->id, 404);

        $data = $request->validate([
            'type' => ['required', Rule::in(self::TYPES)],
            'value' => ['nullable', 'string'], // blank = keep existing secret
            'expires_at' => ['nullable', 'date'],
        ]);

        $credential->type = $data['type'];
        $credential->expires_at = $data['expires_at'] ?? null;

        if (! empty($data['value'])) {
            $credential->setSecret($data['value']);
        }

        $credential->save();
        AuditLog::record('connector.credential.rotated', $connector, ['key' => $credential->key, 'value_changed' => ! empty($data['value'])], $this->context->id());

        return back()->with('status', "Credential “{$credential->key}” updated.");
    }

    public function destroy(Connector $connector, ConnectorCredential $credential): RedirectResponse
    {
        abort_unless($credential->connector_id === $connector->id, 404);

        AuditLog::record('connector.credential.deleted', $connector, ['key' => $credential->key], $this->context->id());
        $credential->delete();

        return back()->with('status', 'Credential deleted.');
    }
}
