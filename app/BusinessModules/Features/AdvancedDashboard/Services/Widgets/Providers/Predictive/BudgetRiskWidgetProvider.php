<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class BudgetRiskWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::BUDGET_RISK;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $projects = DB::table('projects')
            ->where('organization_id', $request->organizationId)
            ->where('status', '!=', 'completed')
            ->whereNotNull('budget_amount')
            ->select('id', 'name', 'budget_amount')
            ->get();

        $risks = [];

        foreach ($projects as $project) {
            $spent = $this->getProjectSpent($project->id);
            $budget = (float)$project->budget_amount;
            $utilization = $budget > 0 ? ($spent / $budget) * 100 : 0;

            if ($utilization > 75) {
                $risks[] = [
                    'project_id' => $project->id,
                    'project_name' => $project->name,
                    'budget' => $budget,
                    'spent' => $spent,
                    'utilization' => round($utilization, 2),
                    'risk_level' => $utilization > 100 ? 'critical' : ($utilization > 90 ? 'high' : 'medium'),
                ];
            }
        }

        usort($risks, fn($a, $b) => $b['utilization'] <=> $a['utilization']);

        return ['risks' => $risks, 'total_at_risk' => count($risks)];
    }

    protected function getProjectSpent(int $projectId): float
    {
        $result = DB::table('completed_works')
            ->where('project_id', $projectId)
            ->sum(DB::raw('quantity * price'));
        return $result ? (float)$result : 0.0;
    }
}

