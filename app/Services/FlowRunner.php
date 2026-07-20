<?php

namespace App\Services;

use App\Jobs\RunExecutionJob;
use App\Models\ExecutionJob;
use App\Models\Flow;
use App\Models\Module;
use App\Models\WebhookPayload;

/**
 * Turns an accepted webhook payload into queued execution jobs by matching it
 * against active flows (trigger connector + entity), applying field mappings to
 * build the job input, and dispatching them to the queue.
 *
 * Before dispatching, each job is validated: the target module must exist and be
 * ENABLED, all mapped source fields must be present, and every field the module's
 * input schema declares must be populated. A job that fails validation is
 * recorded as `failed` with a clear error and is NOT dispatched — incomplete
 * payloads never run as if they succeeded.
 */
class FlowRunner
{
    public function __construct(private readonly MappingService $mapper) {}

    /**
     * @return int number of execution jobs successfully dispatched to the queue
     */
    public function handlePayload(WebhookPayload $payload): int
    {
        $entity = $payload->endpoint?->entity;

        $flows = Flow::withoutWorkspaceScope()
            ->where('workspace_id', $payload->workspace_id)
            ->where('status', 'active')
            ->get()
            ->filter(fn (Flow $f) => $f->triggerConnectorId() === $payload->connector_id
                && $f->triggerEntity() === $entity);

        $dispatched = 0;
        $errors = [];

        foreach ($flows as $flow) {
            $result = $this->dispatchFor($flow, $payload, $entity);

            if ($result['dispatched']) {
                $dispatched++;
            } else {
                $errors = array_merge($errors, $result['errors']);
            }
        }

        // Reflect the real outcome on the payload log.
        if ($dispatched > 0) {
            $payload->forceFill(['status' => 'processed', 'processed_at' => now()])->save();
        } elseif ($errors !== []) {
            $payload->forceFill([
                'status' => 'failed',
                'error' => implode(' ', array_unique($errors)),
            ])->save();
        }

        return $dispatched;
    }

    /**
     * @return array{dispatched: bool, errors: array<int, string>}
     */
    private function dispatchFor(Flow $flow, WebhookPayload $payload, ?string $entity): array
    {
        $slug = $flow->actionModule() ?? '';
        $mapped = $this->mapper->applyFor($payload->connector, $entity, $payload->parsed_payload ?? []);
        $errors = $this->validate($slug, $mapped);

        $job = ExecutionJob::create([
            'workspace_id' => $payload->workspace_id,
            'flow_id' => $flow->id,
            'payload_id' => $payload->id,
            'connector_id' => $payload->connector_id,
            'type' => $slug,
            'status' => $errors === [] ? 'pending' : 'failed',
            'input' => $mapped['mapped'],
            'error' => $errors === [] ? null : implode(' ', $errors),
            'finished_at' => $errors === [] ? null : now(),
        ]);

        if ($errors !== []) {
            return ['dispatched' => false, 'errors' => $errors];
        }

        RunExecutionJob::dispatch($job->id);

        return ['dispatched' => true, 'errors' => []];
    }

    /**
     * Validate that the payload can safely run: module present + enabled, all
     * mapped source fields resolved, and every declared input-schema field set.
     *
     * @param  array{mapped: array<string, mixed>, missing: array<int, string>}  $mapped
     * @return array<int, string> human-readable validation errors (empty = valid)
     */
    private function validate(string $slug, array $mapped): array
    {
        if ($slug === '') {
            return ['Flow has no action module configured.'];
        }

        $errors = [];

        // Fields the payload was expected to supply but didn't.
        if (! empty($mapped['missing'])) {
            $errors[] = 'Incomplete payload — missing source field(s): '.implode(', ', $mapped['missing']).'.';
        }

        // Module must exist in the registry and be enabled.
        $module = Module::where('slug', $slug)->first();

        if (! $module) {
            $errors[] = "Module “{$slug}” is not registered.";

            return $errors;
        }

        if (! $module->enabled) {
            $errors[] = "Module “{$slug}” is disabled and cannot be executed.";

            return $errors;
        }

        // Every field declared by the module's input schema must be populated.
        // Same check RunExecutionJob enforces on execution/retry — kept in one place.
        foreach ($module->missingRequiredInput($mapped['mapped']) as $field) {
            $errors[] = "Required input field “{$field}” is missing for module “{$slug}”.";
        }

        return $errors;
    }
}
