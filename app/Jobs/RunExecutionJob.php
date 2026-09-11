<?php

namespace App\Jobs;

use App\Models\ExecutionJob;
use App\Models\Module;
use App\Modules\ExecutionContext;
use App\Modules\ModuleRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Processes one ExecutionJob row: resolves the module from the registry, runs it,
 * and records the outcome (completed/failed) with timing and attempt count.
 */
class RunExecutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $executionJobId) {}

    public function handle(ModuleRegistry $registry): void
    {
        $job = ExecutionJob::withoutWorkspaceScope()->find($this->executionJobId);

        if (! $job || $job->status === 'completed') {
            return;
        }

        $job->forceFill(['status' => 'processing', 'started_at' => now()])->save();

        try {
            // Authoritative on/off check: a module disabled in the admin panel
            // must never execute, even for jobs queued before it was disabled or
            // retried afterwards.
            $moduleRow = Module::where('slug', $job->type)->first();

            if ($moduleRow && ! $moduleRow->enabled) {
                throw new \RuntimeException("Module “{$job->type}” is disabled and cannot be executed.");
            }

            $module = $registry->find($job->type);

            if (! $module) {
                throw new \RuntimeException("Module “{$job->type}” is not registered.");
            }

            // Fail closed on a connector/workspace mismatch (client-flagged fix, 2026-09-11 —
            // M7 "tenant-mapping and cross-tenant isolation" review): nothing previously checked
            // that $job->connector actually belongs to $job->workspace before binding both into
            // one ExecutionContext. A job whose connector_id pointed at a DIFFERENT workspace's
            // connector would execute using that other workspace's real credentials while
            // labeled under this job's own workspace/tenant — a genuine cross-tenant leak path,
            // not merely a replay-key collision (which the entity-aware key fix already covers).
            if ($job->connector && $job->connector->workspace_id !== $job->workspace_id) {
                throw new \RuntimeException('This execution job’s connector does not belong to its workspace — refusing to execute across a tenant boundary.');
            }

            // Re-validate required input on EVERY execution (including retries). A
            // job whose mapped input is still missing required fields must stay
            // failed — retrying without fixing the data can never complete it.
            if ($moduleRow) {
                $missing = $moduleRow->missingRequiredInput($job->input ?? []);

                if ($missing !== []) {
                    throw new \RuntimeException('Incomplete input — missing required field(s): '.implode(', ', $missing).'.');
                }
            }

            // Client-requested fix (2026-09-05 review): propagate the job's correlation id into
            // ExecutionContext::$meta so a module can carry it through to whatever it delivers
            // to next (e.g. ZaiKpiDelivery's outbound X-Correlation-ID header), completing the
            // source → Connector → ZaiKPI trace.
            $context = new ExecutionContext($job->workspace, $job->connector, ['correlation_id' => $job->correlation_id]);
            $result = $module->execute($job->input ?? [], $context);

            $job->forceFill([
                'status' => $result->success ? 'completed' : 'failed',
                'result' => $result->output,
                'error' => $result->error,
                'attempts' => $job->attempts + 1,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $job->forceFill([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'attempts' => $job->attempts + 1,
                'finished_at' => now(),
            ])->save();
        }
    }
}
