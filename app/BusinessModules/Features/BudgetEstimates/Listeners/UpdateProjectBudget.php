<?php

namespace App\BusinessModules\Features\BudgetEstimates\Listeners;

use App\BusinessModules\Features\BudgetEstimates\Events\EstimateApproved;
use App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateProjectIntegrationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class UpdateProjectBudget implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct(
        protected EstimateProjectIntegrationService $integrationService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(EstimateApproved $event): void
    {
        $estimate = $event->estimate;

        if (!$estimate->project_id) {
            return;
        }

        try {
            // Синхронизировать бюджет проекта
            $this->integrationService->syncWithProjectBudget($estimate);
            
            // Обновить статус проекта
            $this->integrationService->updateProjectStatus($estimate);
            
            \Log::info('estimate.project_budget_updated', [
                'estimate_id' => $estimate->id,
                'project_id' => $estimate->project_id,
                'budget_amount' => $estimate->total_amount_with_vat,
            ]);
        } catch (\Exception $e) {
            \Log::error('estimate.project_budget_update_failed', [
                'estimate_id' => $estimate->id,
                'project_id' => $estimate->project_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

