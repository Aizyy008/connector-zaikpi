<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunExecutionJob;
use App\Models\AuditLog;
use App\Models\ExecutionJob;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Queue & Logs — monitoring for the execution layer plus manual retry.
 */
class ExecutionJobController extends Controller
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function index(Request $request): View
    {
        $status = $request->query('status');

        $jobs = ExecutionJob::with(['flow', 'connector'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $counts = ExecutionJob::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $retried = ExecutionJob::where('attempts', '>', 1)->count();

        return view('admin.queue.index', compact('jobs', 'counts', 'status', 'retried'));
    }

    public function show(ExecutionJob $job): View
    {
        $job->load(['flow', 'connector', 'payload']);

        return view('admin.queue.show', compact('job'));
    }

    public function retry(ExecutionJob $job): RedirectResponse
    {
        if (! in_array($job->status, ['failed', 'held'], true)) {
            return back()->with('error', 'Only failed or held jobs can be retried.');
        }

        $job->forceFill(['status' => 'pending', 'error' => null])->save();
        RunExecutionJob::dispatch($job->id);

        AuditLog::record('execution.retried', $job, ['attempt' => $job->attempts + 1], $this->context->id());

        return back()->with('status', "Job #{$job->id} re-queued.");
    }
}
