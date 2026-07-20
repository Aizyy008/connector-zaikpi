<?php

namespace App\Modules;

/**
 * Outcome of a module execution. Consumed by the execution layer in Milestone 5.
 */
class ExecutionResult
{
    public function __construct(
        public readonly bool $success,
        public readonly array $output = [],
        public readonly ?string $error = null,
    ) {}

    public static function ok(array $output = []): self
    {
        return new self(true, $output);
    }

    public static function fail(string $error, array $output = []): self
    {
        return new self(false, $output, $error);
    }
}
