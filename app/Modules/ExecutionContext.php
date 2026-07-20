<?php

namespace App\Modules;

use App\Models\Connector;
use App\Models\Workspace;

/**
 * Runtime context handed to a module's execute(). Expanded in Milestone 5 when the
 * execution/queue layer drives modules end-to-end.
 */
class ExecutionContext
{
    public function __construct(
        public readonly Workspace $workspace,
        public readonly ?Connector $connector = null,
        public readonly array $meta = [],
    ) {}
}
