<?php

namespace App\Contracts;

use App\Modules\ExecutionContext;
use App\Modules\ExecutionResult;
use App\Modules\ModuleHealth;

/**
 * A module is a contract-driven unit of capability (trigger / action / transform).
 *
 * Adding a module = implement this interface and register the class in
 * config/modules.php, then run `php artisan modules:sync`. The core never needs
 * editing to gain a new module — this is what keeps the platform extensible for a
 * future SDK / marketplace.
 */
interface ModuleContract
{
    /** Stable unique key referenced by routing/flows (e.g. "businessapp.create_invoice"). */
    public function slug(): string;

    public function name(): string;

    /** trigger | action | transform */
    public function type(): string;

    public function description(): string;

    /** How the module runs: sync | queue | webhook. */
    public function executionMethod(): string;

    /** @return array<int, string> declared action identifiers */
    public function actions(): array;

    /** @return array<string, mixed> JSON-schema-ish input contract */
    public function inputSchema(): array;

    /** @return array<string, mixed> JSON-schema-ish output contract */
    public function outputSchema(): array;

    /** @return array<int, string> required permission scopes */
    public function scopes(): array;

    /** Self-check the module reports to the registry / dashboard. */
    public function healthCheck(): ModuleHealth;

    public function execute(array $input, ExecutionContext $context): ExecutionResult;
}
