<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Projects;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyzeProjectRisksAction
{
    public function execute(int $organizationId, ?array $params = []): array
    {
        $projectId = $params['project_id'] ?? null;
        $today = Carbon::today();
        $warningDays = 30;

        $projects = DB::table('projects')
            ->leftJoin('completed_works', function($join) {
                $join->on('projects.id', '=', 'completed_works.project_id')
                     ->where('completed_works.status', '=', 'confirmed')
                     ->whereNull('completed_works.deleted_at');
            })
            ->where('projects.organization_id', $organizationId)
            ->where('projects.status', '!=', 'completed')
            ->where('projects.status', '!=', 'cancelled')
            ->where('projects.is_archived', false)
            ->whereNull('projects.deleted_at')
            ->when($projectId, function($query, $id) {
                return $query->where('projects.id', $id);
            })
            ->select(
                'projects.id',
                'projects.name',
                'projects.status',
                'projects.budget_amount',
                'projects.start_date',
                'projects.end_date',
                DB::raw('COALESCE(SUM(completed_works.total_amount), 0) as spent')
            )
            ->groupBy(
                'projects.id',
                'projects.name',
                'projects.status',
                'projects.budget_amount',
                'projects.start_date',
                'projects.end_date'
            )
            ->get();

        $deadlineRisks = [];
        $budgetRisks = [];
        $allRisks = [];

        foreach ($projects as $project) {
            $risks = [];
            $riskLevel = 'low';

            $budget = (float)($project->budget_amount ?? 0);
            $spent = (float)$project->spent;
            $budgetPercentage = $budget > 0 ? ($spent / $budget) * 100 : 0;

            if ($project->end_date) {
                $endDate = Carbon::parse($project->end_date);
                $daysRemaining = $today->diffInDays($endDate, false);

                if ($daysRemaining < 0) {
                    $risks[] = 'Срок выполнения прошел на ' . abs($daysRemaining) . ' дн.';
                    $riskLevel = 'high';
                    $deadlineRisks[] = [
                        'id' => $project->id,
                        'name' => $project->name,
                        'end_date' => $project->end_date,
                        'days_overdue' => abs($daysRemaining),
                    ];
                } elseif ($daysRemaining <= $warningDays) {
                    $risks[] = 'До дедлайна осталось ' . $daysRemaining . ' дн.';
                    if ($riskLevel === 'low') {
                        $riskLevel = 'medium';
                    }
                    $deadlineRisks[] = [
                        'id' => $project->id,
                        'name' => $project->name,
                        'end_date' => $project->end_date,
                        'days_remaining' => $daysRemaining,
                    ];
                }
            }

            if ($budgetPercentage >= 100) {
                $risks[] = 'Бюджет превышен на ' . round($budgetPercentage - 100, 2) . '%';
                $riskLevel = 'high';
                $budgetRisks[] = [
                    'id' => $project->id,
                    'name' => $project->name,
                    'budget' => $budget,
                    'spent' => $spent,
                    'percentage_used' => round($budgetPercentage, 2),
                ];
            } elseif ($budgetPercentage >= 80) {
                $risks[] = 'Потрачено ' . round($budgetPercentage, 2) . '% бюджета';
                if ($riskLevel === 'low') {
                    $riskLevel = 'medium';
                }
                $budgetRisks[] = [
                    'id' => $project->id,
                    'name' => $project->name,
                    'budget' => $budget,
                    'spent' => $spent,
                    'percentage_used' => round($budgetPercentage, 2),
                ];
            }

            if (!empty($risks)) {
                $allRisks[] = [
                    'id' => $project->id,
                    'name' => $project->name,
                    'status' => $project->status,
                    'risk_level' => $riskLevel,
                    'risks' => $risks,
                    'budget_percentage' => round($budgetPercentage, 2),
                    'end_date' => $project->end_date,
                ];
            }
        }

        return [
            'projects_at_risk' => count($allRisks),
            'deadline_risks_count' => count($deadlineRisks),
            'budget_risks_count' => count($budgetRisks),
            'deadline_risks' => $deadlineRisks,
            'budget_risks' => $budgetRisks,
            'all_risks' => $allRisks,
        ];
    }
}

