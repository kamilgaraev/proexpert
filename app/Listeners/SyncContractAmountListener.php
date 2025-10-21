<?php

namespace App\Listeners;

use App\Events\EstimateApproved;
use App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateContractIntegrationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SyncContractAmountListener implements ShouldQueue
{
    public function __construct(
        protected EstimateContractIntegrationService $contractIntegration
    ) {}

    public function handle(EstimateApproved $event): void
    {
        $estimate = $event->estimate;
        
        if ($estimate->contract_id) {
            $this->contractIntegration->syncContractAmount($estimate);
        }
    }
}

