<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProjectsRisksWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::PROJECTS_RISKS;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $projects = DB::table('projects')
            ->where('organization_id', $request->organizationId)
            ->where('status', '!=', 'completed')
            ->select('id', 'name', 'budget_amount', 'start_date', 'end_date', 'status')
            ->get();

        $risksData = [];
        $now = Carbon::now();

        foreach ($projects as $project) {
            $budgetRisk = $this->calculateBudgetRisk($project);
            $timelineRisk = $this->calculateTimelineRisk($project, $now);
            $overallRisk = ($budgetRisk + $timelineRisk) / 2;

            if ($overallRisk > 0.3) {
                $risksData[] = [
                    'project_id' => $project->id,
                    'project_name' => $project->name,
                    'budget_risk' => round($budgetRisk, 2),
                    'timeline_risk' => round($timelineRisk, 2),
                    'overall_risk' => round($overallRisk, 2),
                    'risk_level' => $this->getRiskLevel($overallRisk),
                    'recommendations' => $this->getRecommendations($budgetRisk, $timelineRisk),
                ];
            }
        }

        usort($risksData, fn($a, $b) => $b['overall_risk'] <=> $a['overall_risk']);

        return [
            'high_risk_projects' => array_filter($risksData, fn($p) => $p['overall_risk'] >= 0.7),
            'medium_risk_projects' => array_filter($risksData, fn($p) => $p['overall_risk'] >= 0.4 && $p['overall_risk'] < 0.7),
            'low_risk_projects' => array_filter($risksData, fn($p) => $p['overall_risk'] < 0.4),
            'total_at_risk' => count($risksData),
        ];
    }

    protected function calculateBudgetRisk(object $project): float
    {
        $budgetAmount = (float)$project->budget_amount;
        if ($budgetAmount == 0) return 0;

        $spent = $this->getProjectSpent($project->id);
        $utilization = $spent / $budgetAmount;

        if ($utilization > 1.0) return 1.0;
        if ($utilization > 0.9) return 0.8;
        if ($utilization > 0.75) return 0.5;
        return 0.2;
    }

    protected function calculateTimelineRisk(object $project, Carbon $now): float
    {
        if (!$project->end_date) return 0.3;

        $endDate = Carbon::parse($project->end_date);
        if ($now->greaterThan($endDate)) return 1.0;

        $daysRemaining = $now->diffInDays($endDate);
        if ($daysRemaining < 7) return 0.9;
        if ($daysRemaining < 30) return 0.6;
        if ($daysRemaining < 60) return 0.3;
        return 0.1;
    }

    protected function getRiskLevel(float $risk): string
    {
        if ($risk >= 0.7) return 'high';
        if ($risk >= 0.4) return 'medium';
        return 'low';
    }

    protected function getRecommendations(float $budgetRisk, float $timelineRisk): array
    {
        $recommendations = [];

        if ($budgetRisk > 0.7) {
            $recommendations[] = 'Критическое превышение бюджета - требуется немедленное вмешательство';
        } elseif ($budgetRisk > 0.4) {
            $recommendations[] = 'Контролируйте расходы - приближение к лимиту бюджета';
        }

        if ($timelineRisk > 0.7) {
            $recommendations[] = 'Высокий риск срыва сроков - рассмотрите возможность увеличения ресурсов';
        } elseif ($timelineRisk > 0.4) {
            $recommendations[] = 'Следите за графиком выполнения работ';
        }

        return $recommendations;
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

