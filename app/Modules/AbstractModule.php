<?php

namespace App\Modules;

use App\Contracts\ModuleContract;

/**
 * Sensible defaults for modules. Concrete modules override what they need.
 */
abstract class AbstractModule implements ModuleContract
{
    abstract public function slug(): string;

    abstract public function name(): string;

    abstract public function type(): string;

    public function description(): string
    {
        return '';
    }

    public function executionMethod(): string
    {
        return 'queue';
    }

    public function actions(): array
    {
        return [];
    }

    public function inputSchema(): array
    {
        return [];
    }

    public function outputSchema(): array
    {
        return [];
    }

    public function scopes(): array
    {
        return [];
    }

    public function healthCheck(): ModuleHealth
    {
        return ModuleHealth::Healthy;
    }

    public function execute(array $input, ExecutionContext $context): ExecutionResult
    {
        return ExecutionResult::ok();
    }
}
