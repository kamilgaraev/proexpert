<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class CostOverrunWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::COST_OVERRUN;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $projects = DB::table('projects')
            ->where('organization_id', $request->organizationId)
            ->whereNotNull('budget_amount')
            ->get();

        $overruns = [];
        $totalOverrun = 0;

        foreach ($projects as $project) {
            $budget = (float)$project->budget_amount;
            $spent = $this->getProjectSpent($project->id);
            $overrun = $spent - $budget;
            $overrunPercent = $budget > 0 ? ($overrun / $budget) * 100 : 0;

            if ($overrun > 0 || $overrunPercent > -10) { // Показываем проекты близкие к бюджету
                $overruns[] = [
                    'project_id' => $project->id,
                    'project_name' => $project->name,
                    'budget' => $budget,
                    'spent' => $spent,
                    'remaining' => max(0, $budget - $spent),
                    'overrun' => $overrun,
                    'overrun_percent' => round($overrunPercent, 2),
                    'status' => $this->getOverrunStatus($overrunPercent),
                ];

                if ($overrun > 0) {
                    $totalOverrun += $overrun;
                }
            }
        }

        usort($overruns, fn($a, $b) => $b['overrun_percent'] <=> $a['overrun_percent']);

        return [
            'cost_overruns' => $overruns,
            'projects_over_budget' => count(array_filter($overruns, fn($o) => $o['overrun'] > 0)),
            'total_overrun' => round($totalOverrun, 2),
            'projects_at_risk' => count(array_filter($overruns, fn($o) => $o['overrun_percent'] > 90)),
        ];
    }

    protected function getProjectSpent(int $projectId): float
    {
        $spent = DB::table('completed_works')
            ->where('project_id', $projectId)
            ->sum(DB::raw('quantity * price')) ?: 0;

        return (float)$spent;
    }

    protected function getOverrunStatus(float $overrunPercent): string
    {
        if ($overrunPercent > 100) return 'critical';
        if ($overrunPercent > 90) return 'high';
        if ($overrunPercent > 75) return 'warning';
        return 'normal';
    }
}
