<?php

namespace App\Modules\Builtin;

use App\Modules\AbstractModule;
use App\Modules\ExecutionContext;
use App\Modules\ExecutionResult;
use App\Modules\ModuleHealth;

/**
 * Action that creates an invoice in the business system. Execution is stubbed
 * until the execution layer lands in Milestone 5.
 */
class CreateInvoiceAction extends AbstractModule
{
    public function slug(): string
    {
        return 'businessapp.create_invoice';
    }

    public function name(): string
    {
        return 'Create Invoice';
    }

    public function type(): string
    {
        return 'action';
    }

    public function description(): string
    {
        return 'Creates an invoice in the connected business system from a canonical order payload.';
    }

    public function actions(): array
    {
        return ['create_invoice'];
    }

    public function inputSchema(): array
    {
        return [
            'external_order_id' => 'string',
            'customer_reference' => 'string',
            'currency' => 'string',
            'subtotal' => 'number',
        ];
    }

    public function outputSchema(): array
    {
        return [
            'invoice_reference' => 'string',
            'status' => 'string',
        ];
    }

    public function scopes(): array
    {
        return ['flows.execute'];
    }

    public function healthCheck(): ModuleHealth
    {
        return ModuleHealth::Healthy;
    }

    public function execute(array $input, ExecutionContext $context): ExecutionResult
    {
        // Placeholder — real business-system call arrives with the execution layer (M5).
        return ExecutionResult::ok([
            'invoice_reference' => 'INV-PENDING',
            'status' => 'draft_reviewed',
        ]);
    }
}
