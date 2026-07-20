<?php

namespace App\Console\Commands;

use App\Modules\ModuleRegistry;
use Illuminate\Console\Command;

class SyncModules extends Command
{
    protected $signature = 'modules:sync';

    protected $description = 'Sync code-defined modules (config/modules.php) into the module registry table';

    public function handle(ModuleRegistry $registry): int
    {
        $result = $registry->sync();
        $this->info("Synced {$result['synced']} module(s) into the registry.");

        return self::SUCCESS;
    }
}
