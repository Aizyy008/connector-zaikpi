<?php

use App\Modules\Builtin\CreateInvoiceAction;
use App\Modules\Builtin\WebhookReceivedTrigger;
use App\Modules\ZaiKpi\PullMeasurementsAction;
use App\Modules\ZaiKpi\PushKpiDefinitionAction;
use App\Modules\ZaiKpi\ZaiKpiEventTrigger;

return [

    /*
    |--------------------------------------------------------------------------
    | Registered Modules
    |--------------------------------------------------------------------------
    |
    | Each entry is a class implementing App\Contracts\ModuleContract. Add a
    | module by implementing the contract and listing it here, then run
    | `php artisan modules:sync`. The core is never edited to add a module —
    | this is the extension point for a future SDK / marketplace.
    |
    */

    'registered' => [
        WebhookReceivedTrigger::class,
        CreateInvoiceAction::class,

        // ZaiKPI ↔ FlinkISO integration (ZaiKPI-specific modules; platform core unchanged)
        PushKpiDefinitionAction::class,   // inbound  FlinkISO → ZaiKPI
        PullMeasurementsAction::class,    // outbound ZaiKPI → FlinkISO
        ZaiKpiEventTrigger::class,        // outbound events via webhook intake
    ],

];
