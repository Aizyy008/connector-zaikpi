<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WebhookPayload;
use App\Services\MappingService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PayloadController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');

        $payloads = WebhookPayload::with(['connector', 'endpoint'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('received_at')
            ->paginate(20)
            ->withQueryString();

        $counts = WebhookPayload::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.payloads.index', compact('payloads', 'counts', 'status'));
    }

    public function show(WebhookPayload $payload, MappingService $mapper): View
    {
        $payload->load(['connector', 'endpoint']);

        // Live mapping preview: apply the configured field mappings to this payload.
        $preview = null;
        if (is_array($payload->parsed_payload)) {
            $entity = $payload->endpoint?->entity;
            $preview = $mapper->applyFor($payload->connector, $entity, $payload->parsed_payload);
        }

        return view('admin.payloads.show', compact('payload', 'preview'));
    }
}
