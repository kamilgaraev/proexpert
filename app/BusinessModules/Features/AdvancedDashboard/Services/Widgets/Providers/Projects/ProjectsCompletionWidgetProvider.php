<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class ProjectsCompletionWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::PROJECTS_COMPLETION;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $projects = DB::table('projects')
            ->where('organization_id', $request->organizationId)
            ->whereNotNull('budget_amount')
            ->select('id', 'name', 'budget_amount', 'status')
            ->get();

        $completionData = [];

        foreach ($projects as $project) {
            $budgetAmount = (float)$project->budget_amount;
            $completedWorkValue = $this->getCompletedWorkValue($project->id);
            $completion = $budgetAmount > 0 ? round(($completedWorkValue / $budgetAmount) * 100, 2) : 0;

            $completionData[] = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'completion_percentage' => min(100, $completion),
                'completed_value' => $completedWorkValue,
                'total_value' => $budgetAmount,
                'remaining_value' => max(0, $budgetAmount - $completedWorkValue),
                'status' => $project->status,
            ];
        }

        usort($completionData, fn($a, $b) => $b['completion_percentage'] <=> $a['completion_percentage']);

        $avgCompletion = count($completionData) > 0
            ? round(array_sum(array_column($completionData, 'completion_percentage')) / count($completionData), 2)
            : 0;

        return [
            'average_completion' => $avgCompletion,
            'projects' => $completionData,
            'fully_completed_count' => count(array_filter($completionData, fn($p) => $p['completion_percentage'] >= 100)),
            'in_progress_count' => count(array_filter($completionData, fn($p) => $p['completion_percentage'] > 0 && $p['completion_percentage'] < 100)),
        ];
    }

    protected function getCompletedWorkValue(int $projectId): float
    {
        $result = DB::table('completed_works')
            ->where('project_id', $projectId)
            ->sum(DB::raw('quantity * price'));

        return $result ? (float)$result : 0.0;
    }
}

