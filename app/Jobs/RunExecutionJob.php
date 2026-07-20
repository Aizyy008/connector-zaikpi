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

            // Re-validate required input on EVERY execution (including retries). A
            // job whose mapped input is still missing required fields must stay
            // failed — retrying without fixing the data can never complete it.
            if ($moduleRow) {
                $missing = $moduleRow->missingRequiredInput($job->input ?? []);

                if ($missing !== []) {
                    throw new \RuntimeException('Incomplete input — missing required field(s): '.implode(', ', $missing).'.');
                }
            }

            $context = new ExecutionContext($job->workspace, $job->connector);
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
