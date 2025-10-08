<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProjectCompletionWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::PROJECT_COMPLETION;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $projects = DB::table('projects')
            ->where('organization_id', $request->organizationId)
            ->whereIn('status', ['active', 'in_progress'])
            ->get();

        $forecasts = [];
        
        foreach ($projects as $project) {
            $completion = $this->calculateProjectCompletion($project->id);
            $forecast = $this->forecastCompletionDate($project, $completion);
            
            $forecasts[] = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'current_completion' => $completion,
                'planned_end_date' => $project->end_date,
                'forecasted_end_date' => $forecast['date'],
                'delay_days' => $forecast['delay_days'],
                'status' => $forecast['status'],
            ];
        }

        usort($forecasts, fn($a, $b) => $b['delay_days'] <=> $a['delay_days']);

        return [
            'forecasts' => $forecasts,
            'projects_at_risk' => count(array_filter($forecasts, fn($f) => $f['delay_days'] > 0)),
            'total_projects' => count($forecasts),
        ];
    }

    protected function calculateProjectCompletion(int $projectId): float
    {
        // Считаем процент выполнения на основе выполненных работ
        $totalBudget = DB::table('projects')
            ->where('id', $projectId)
            ->value('budget_amount') ?: 0;

        if ($totalBudget == 0) {
            return 0;
        }

        $completedValue = DB::table('completed_works')
            ->where('project_id', $projectId)
            ->sum(DB::raw('quantity * price')) ?: 0;

        return min(100, round(($completedValue / $totalBudget) * 100, 2));
    }

    protected function forecastCompletionDate($project, float $completion): array
    {
        $startDate = $project->start_date ? Carbon::parse($project->start_date) : Carbon::now()->subMonths(3);
        $plannedEndDate = $project->end_date ? Carbon::parse($project->end_date) : Carbon::now()->addMonths(3);
        $today = Carbon::now();

        // Сколько дней прошло
        $daysPassed = max(1, $startDate->diffInDays($today));
        
        // Скорость выполнения (% в день)
        $velocity = $daysPassed > 0 ? $completion / $daysPassed : 0;

        if ($velocity == 0) {
            return [
                'date' => $plannedEndDate->toIso8601String(),
                'delay_days' => $plannedEndDate->diffInDays($today, false),
                'status' => 'unknown',
            ];
        }

        // Сколько еще дней потребуется
        $remainingDays = ceil((100 - $completion) / $velocity);
        $forecastedEndDate = $today->copy()->addDays($remainingDays);

        $delayDays = $forecastedEndDate->diffInDays($plannedEndDate, false);

        $status = 'on_track';
        if ($delayDays < -7) {
            $status = 'ahead';
        } elseif ($delayDays > 7) {
            $status = 'delayed';
        }

        return [
            'date' => $forecastedEndDate->toIso8601String(),
            'delay_days' => -$delayDays, // отрицательное = опережение
            'status' => $status,
        ];
    }
}
