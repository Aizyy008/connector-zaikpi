<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Connector;
use App\Models\ExecutionJob;
use App\Models\FieldMapping;
use App\Models\Flow;
use App\Models\Module;
use App\Models\WebhookPayload;
use App\Support\WorkspaceContext;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Operational dashboard. Every figure is scoped to the user's active workspace
 * (via the BelongsToWorkspace global scope) and therefore reflects exactly what
 * the logged-in user is allowed to see.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function index(): View
    {
        $workspace = $this->context->get();

        // --- Connectors (workspace-scoped) --------------------------------
        $connectors = Connector::orderBy('name')->get();
        $connectorHealth = [
            'total' => $connectors->count(),
            'healthy' => $connectors->where('status', 'healthy')->count(),
            'warning' => $connectors->where('status', 'warning')->count(),
            'disconnected' => $connectors->where('status', 'disconnected')->count(),
        ];

        // --- Execution jobs ------------------------------------------------
        $jobCounts = ExecutionJob::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $jobs = [
            'pending' => (int) ($jobCounts['pending'] ?? 0),
            'processing' => (int) ($jobCounts['processing'] ?? 0),
            'completed' => (int) ($jobCounts['completed'] ?? 0),
            'failed' => (int) ($jobCounts['failed'] ?? 0),
            'held' => (int) ($jobCounts['held'] ?? 0),
        ];
        $jobs['total'] = array_sum($jobs);
        $jobs['completed_today'] = ExecutionJob::where('status', 'completed')
            ->whereDate('finished_at', Carbon::today())->count();

        // --- Webhook payloads ---------------------------------------------
        $payloadCounts = WebhookPayload::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $payloads = [
            'total' => (int) $payloadCounts->sum(),
            'valid' => (int) (($payloadCounts['valid'] ?? 0) + ($payloadCounts['processed'] ?? 0)),
            'invalid' => (int) (($payloadCounts['invalid'] ?? 0) + ($payloadCounts['failed'] ?? 0)),
            'today' => WebhookPayload::whereDate('received_at', Carbon::today())->count(),
        ];
        $lastPayload = WebhookPayload::latest('received_at')->first();

        // --- Flows, mappings, modules -------------------------------------
        $flows = ['total' => Flow::count(), 'active' => Flow::where('status', 'active')->count()];
        $mappings = ['total' => FieldMapping::count(), 'review' => FieldMapping::where('status', 'review')->count()];
        $modules = [
            'total' => Module::count(),
            'enabled' => Module::where('enabled', true)->count(),
            'unhealthy' => Module::where('health_status', '!=', 'healthy')->count(),
        ];

        // --- Readiness bars (safe division) -------------------------------
        $pct = fn (int $n, int $d): int => $d > 0 ? (int) round($n / $d * 100) : 0;
        $readiness = [
            ['label' => 'Execution success rate', 'desc' => 'Completed jobs vs. all execution jobs.', 'pct' => $pct($jobs['completed'], max($jobs['total'], 1))],
            ['label' => 'Webhook delivery success', 'desc' => 'Valid/processed payloads vs. all received.', 'pct' => $pct($payloads['valid'], max($payloads['total'], 1))],
            ['label' => 'Mapping validation', 'desc' => 'Validated field mappings vs. all mappings.', 'pct' => $pct($mappings['total'] - $mappings['review'], max($mappings['total'], 1))],
            ['label' => 'Connector health', 'desc' => 'Healthy connectors vs. all installed.', 'pct' => $pct($connectorHealth['healthy'], max($connectorHealth['total'], 1))],
        ];

        // --- Alerts (derived from real state) -----------------------------
        $alerts = [];
        foreach ($connectors->where('status', 'disconnected') as $c) {
            $alerts[] = ['title' => "Connector “{$c->name}” is disconnected", 'subtitle' => $c->last_health_status ?: 'Run a health check to diagnose.', 'level' => 'bad', 'route' => route('admin.connectors.index')];
        }
        if ($jobs['failed'] > 0) {
            $alerts[] = ['title' => "{$jobs['failed']} execution job(s) failed", 'subtitle' => 'Failed jobs can be retried from the queue.', 'level' => 'bad', 'route' => route('admin.queue.index', ['status' => 'failed'])];
        }
        if ($jobs['held'] > 0) {
            $alerts[] = ['title' => "{$jobs['held']} job(s) awaiting approval", 'subtitle' => 'Held jobs need a reviewer decision.', 'level' => 'info', 'route' => route('admin.queue.index', ['status' => 'held'])];
        }
        if ($mappings['review'] > 0) {
            $alerts[] = ['title' => "{$mappings['review']} mapping(s) need review", 'subtitle' => 'Schema drift or unvalidated value rules.', 'level' => 'warn', 'route' => route('admin.mappings.index')];
        }
        foreach ($connectors->where('status', 'warning') as $c) {
            $alerts[] = ['title' => "Connector “{$c->name}” needs attention", 'subtitle' => $c->last_health_status ?: 'Warning state.', 'level' => 'warn', 'route' => route('admin.connectors.index')];
        }

        // --- Recent operational events (jobs + payloads, newest first) -----
        $recent = collect();
        foreach (ExecutionJob::with('flow')->latest()->limit(6)->get() as $j) {
            $recent->push([
                'time' => $j->finished_at ?? $j->created_at,
                'event' => 'Execution: '.($j->type ?: 'job').($j->flow ? " ({$j->flow->name})" : ''),
                'module' => 'Queue & Logs',
                'status' => $j->status,
            ]);
        }
        foreach (WebhookPayload::latest('received_at')->limit(6)->get() as $p) {
            $recent->push([
                'time' => $p->received_at ?? $p->created_at,
                'event' => 'Webhook payload received'.($p->error ? " — {$p->error}" : ''),
                'module' => 'Webhooks',
                'status' => $p->status,
            ]);
        }
        $recent = $recent->filter(fn ($e) => $e['time'])->sortByDesc('time')->take(8)->values();

        // --- Environment context ------------------------------------------
        $context = [
            'environment' => ucfirst($workspace?->environment ?? config('app.env')),
            'workspace' => $workspace?->name ?? '—',
            'connectors' => $connectorHealth['total'],
            'last_payload' => $lastPayload?->received_at,
        ];

        return view('admin.dashboard', compact(
            'workspace', 'context', 'connectors', 'connectorHealth', 'jobs',
            'payloads', 'flows', 'mappings', 'modules', 'readiness', 'alerts', 'recent',
        ));
    }
}
