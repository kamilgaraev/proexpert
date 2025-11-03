<?php

namespace App\BusinessModules\Features\BudgetEstimates\Listeners;

use App\BusinessModules\Features\BudgetEstimates\Events\EstimateApproved;
use App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateContractIntegrationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SyncContractAmount implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct(
        protected EstimateContractIntegrationService $integrationService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(EstimateApproved $event): void
    {
        $estimate = $event->estimate;

        if (!$estimate->contract_id) {
            return;
        }

        try {
            // Синхронизировать сумму договора
            // $this->integrationService->syncWithContract($estimate);
            
            \Log::info('estimate.contract_synced', [
                'estimate_id' => $estimate->id,
                'contract_id' => $estimate->contract_id,
                'amount' => $estimate->total_amount_with_vat,
            ]);
        } catch (\Exception $e) {
            \Log::error('estimate.contract_sync_failed', [
                'estimate_id' => $estimate->id,
                'contract_id' => $estimate->contract_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

