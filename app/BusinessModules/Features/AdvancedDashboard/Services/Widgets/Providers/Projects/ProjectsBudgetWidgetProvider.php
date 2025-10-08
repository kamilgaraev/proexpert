<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class ProjectsBudgetWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::PROJECTS_BUDGET;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $projects = DB::table('projects')
            ->where('organization_id', $request->organizationId)
            ->whereNotNull('budget_amount')
            ->select('id', 'name', 'budget_amount', 'status')
            ->get();

        $budgetData = [];
        $totalBudget = 0;
        $totalSpent = 0;

        foreach ($projects as $project) {
            $spent = $this->getProjectSpent($project->id);
            $budget = (float)$project->budget_amount;
            $remaining = $budget - $spent;
            $utilization = $budget > 0 ? round(($spent / $budget) * 100, 2) : 0;

            $budgetData[] = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'budget' => $budget,
                'spent' => $spent,
                'remaining' => $remaining,
                'utilization' => $utilization,
                'status' => $project->status,
                'is_over_budget' => $spent > $budget,
            ];

            $totalBudget += $budget;
            $totalSpent += $spent;
        }

        usort($budgetData, fn($a, $b) => $b['utilization'] <=> $a['utilization']);

        return [
            'total_budget' => $totalBudget,
            'total_spent' => $totalSpent,
            'total_remaining' => $totalBudget - $totalSpent,
            'overall_utilization' => $totalBudget > 0 ? round(($totalSpent / $totalBudget) * 100, 2) : 0,
            'projects' => $budgetData,
            'over_budget_count' => count(array_filter($budgetData, fn($p) => $p['is_over_budget'])),
        ];
    }

    protected function getProjectSpent(int $projectId): float
    {
        $materialCosts = 0.0;
        if (DB::getSchemaBuilder()->hasTable('completed_work_materials')) {
            $result = DB::table('completed_work_materials')
                ->join('completed_works', 'completed_work_materials.completed_work_id', '=', 'completed_works.id')
                ->where('completed_works.project_id', $projectId)
                ->sum('completed_work_materials.total_amount');
            $materialCosts = $result ? (float)$result : 0.0;
        }

        $laborCosts = DB::table('completed_works')
            ->where('project_id', $projectId)
            ->sum(DB::raw('quantity * price * 0.3'));
        $laborCosts = $laborCosts ? (float)$laborCosts : 0.0;

        $contractorCosts = DB::table('material_receipts')
            ->where('project_id', $projectId)
            ->whereIn('status', ['confirmed'])
            ->sum('total_amount');
        $contractorCosts = $contractorCosts ? (float)$contractorCosts : 0.0;

        return $materialCosts + $laborCosts + $contractorCosts;
    }
}

