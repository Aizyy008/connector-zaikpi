<?php

namespace App\Modules;

use App\Contracts\ModuleContract;
use App\Models\Module;
use InvalidArgumentException;

/**
 * Resolves the code-defined modules (config/modules.php) and syncs their declared
 * contract metadata into the `modules` registry table used by the admin UI.
 */
class ModuleRegistry
{
    /**
     * @return array<int, ModuleContract>
     */
    public function all(): array
    {
        return array_map(function (string $class) {
            $module = app($class);

            if (! $module instanceof ModuleContract) {
                throw new InvalidArgumentException("{$class} must implement ModuleContract.");
            }

            return $module;
        }, config('modules.registered', []));
    }

    public function find(string $slug): ?ModuleContract
    {
        foreach ($this->all() as $module) {
            if ($module->slug() === $slug) {
                return $module;
            }
        }

        return null;
    }

    /**
     * Upsert each registered module's contract into the DB registry.
     * Preserves the operational `enabled` flag on existing rows.
     *
     * @return array{synced:int}
     */
    public function sync(): array
    {
        $count = 0;

        foreach ($this->all() as $module) {
            Module::updateOrCreate(
                ['slug' => $module->slug()],
                [
                    'name' => $module->name(),
                    'type' => $module->type(),
                    'description' => $module->description(),
                    'actions' => $module->actions(),
                    'input_schema' => $module->inputSchema(),
                    'output_schema' => $module->outputSchema(),
                    'execution_method' => $module->executionMethod(),
                    'scopes' => $module->scopes(),
                    'health_status' => $module->healthCheck()->value,
                ],
            );
            $count++;
        }

        return ['synced' => $count];
    }
}
