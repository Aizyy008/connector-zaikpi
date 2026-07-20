<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Connector;
use App\Models\WebhookEndpoint;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WebhookEndpointController extends Controller
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function index(): View
    {
        $endpoints = WebhookEndpoint::withCount('payloads')->with('connector')->orderBy('name')->get();

        return view('admin.webhooks.index', compact('endpoints'));
    }

    public function create(): View
    {
        return view('admin.webhooks.form', [
            'endpoint' => new WebhookEndpoint(['enabled' => true, 'signature_header' => 'X-Signature', 'signature_algo' => 'sha256']),
            'connectors' => Connector::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $secret = Str::random(40);

        $endpoint = new WebhookEndpoint($data);
        $endpoint->slug = Str::slug($data['name']).'-'.Str::lower(Str::random(6));
        $endpoint->secret = $secret;
        $endpoint->save();

        AuditLog::record('webhook.endpoint.created', $endpoint, ['name' => $endpoint->name], $this->context->id());

        return redirect()->route('admin.webhooks.index')
            ->with('status', "Endpoint created. Signing secret (shown once): {$secret}");
    }

    public function edit(WebhookEndpoint $webhook): View
    {
        return view('admin.webhooks.form', [
            'endpoint' => $webhook,
            'connectors' => Connector::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, WebhookEndpoint $webhook): RedirectResponse
    {
        $webhook->update($this->validated($request));
        AuditLog::record('webhook.endpoint.updated', $webhook, $webhook->getChanges(), $this->context->id());

        return redirect()->route('admin.webhooks.index')->with('status', 'Endpoint updated.');
    }

    public function regenerate(WebhookEndpoint $webhook): RedirectResponse
    {
        $secret = Str::random(40);
        $webhook->secret = $secret;
        $webhook->save();
        AuditLog::record('webhook.endpoint.secret_rotated', $webhook, [], $this->context->id());

        return back()->with('status', "New signing secret (shown once): {$secret}");
    }

    public function destroy(WebhookEndpoint $webhook): RedirectResponse
    {
        AuditLog::record('webhook.endpoint.deleted', $webhook, ['name' => $webhook->name], $this->context->id());
        $webhook->delete();

        return redirect()->route('admin.webhooks.index')->with('status', 'Endpoint deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'connector_id' => ['nullable', 'integer', Rule::exists('connectors', 'id')->where('workspace_id', $this->context->id())],
            'entity' => ['nullable', 'string', 'max:80'],
            'signature_header' => ['required', 'string', 'max:80'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $data['enabled'] = $request->boolean('enabled');

        return $data;
    }
}
