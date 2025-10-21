<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Integration;

use App\Models\Estimate;
use App\Models\Project;
use App\Repositories\EstimateRepository;
use Illuminate\Support\Collection;

class EstimateProjectIntegrationService
{
    public function __construct(
        protected EstimateRepository $estimateRepository
    ) {}

    public function syncWithProjectBudget(Estimate $estimate): void
    {
        if (!$estimate->project_id) {
            return;
        }
        
        $project = $estimate->project;
        if (!$project) {
            return;
        }
        
        $project->update([
            'budget_amount' => $estimate->total_amount_with_vat
        ]);
    }

    public function compareWithActual(Estimate $estimate): array
    {
        if (!$estimate->project_id) {
            return [
                'error' => 'Смета не привязана к проекту'
            ];
        }
        
        $project = $estimate->project;
        
        $actualCosts = $project->completedWorks()->sum('total_amount');
        
        $plannedAmount = $estimate->total_amount;
        $difference = $actualCosts - $plannedAmount;
        $percentageDeviation = $plannedAmount > 0 
            ? round(($difference / $plannedAmount) * 100, 2) 
            : 0;
        
        return [
            'planned_amount' => (float) $plannedAmount,
            'actual_costs' => (float) $actualCosts,
            'difference' => (float) $difference,
            'percentage_deviation' => $percentageDeviation,
            'status' => $difference > 0 ? 'over_budget' : ($difference < 0 ? 'under_budget' : 'on_budget'),
        ];
    }

    public function updateProjectStatus(Estimate $estimate): void
    {
        if (!$estimate->project_id) {
            return;
        }
        
        $comparison = $this->compareWithActual($estimate);
        
        if (!isset($comparison['error'])) {
            $metadata = $estimate->project->additional_info ?? [];
            $metadata['budget_comparison'] = $comparison;
            $metadata['last_synced_at'] = now()->toISOString();
            
            $estimate->project->update([
                'additional_info' => $metadata
            ]);
        }
    }

    public function getEstimatesByProject(Project $project): Collection
    {
        return $this->estimateRepository->getByProject($project->id);
    }

    public function getTotalEstimatedBudget(Project $project): float
    {
        return (float) Estimate::where('project_id', $project->id)
            ->where('status', 'approved')
            ->sum('total_amount_with_vat');
    }
}

