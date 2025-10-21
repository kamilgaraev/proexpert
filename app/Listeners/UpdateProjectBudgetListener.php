<?php

namespace App\Listeners;

use App\Events\EstimateApproved;
use App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateProjectIntegrationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class UpdateProjectBudgetListener implements ShouldQueue
{
    public function __construct(
        protected EstimateProjectIntegrationService $projectIntegration
    ) {}

    public function handle(EstimateApproved $event): void
    {
        $estimate = $event->estimate;
        
        if ($estimate->project_id) {
            $this->projectIntegration->syncWithProjectBudget($estimate);
            $this->projectIntegration->updateProjectStatus($estimate);
        }
    }
}

