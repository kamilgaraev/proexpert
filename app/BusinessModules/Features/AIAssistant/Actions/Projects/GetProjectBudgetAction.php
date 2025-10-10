<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Projects;

use Illuminate\Support\Facades\DB;

class GetProjectBudgetAction
{
    public function execute(int $organizationId, ?array $params = []): array
    {
        $projectId = $params['project_id'] ?? null;

        $query = DB::table('projects')
            ->where('projects.organization_id', $organizationId)
            ->where('projects.is_archived', false)
            ->whereNull('projects.deleted_at');

        if ($projectId) {
            $query->where('projects.id', $projectId);
        }

        $budgetData = $query
            ->leftJoin('completed_works', function($join) {
                $join->on('projects.id', '=', 'completed_works.project_id')
                     ->where('completed_works.status', '=', 'confirmed')
                     ->whereNull('completed_works.deleted_at');
            })
            ->select(
                'projects.id',
                'projects.name',
                'projects.budget_amount',
                'projects.status',
                DB::raw('COALESCE(SUM(completed_works.total_amount), 0) as spent')
            )
            ->groupBy('projects.id', 'projects.name', 'projects.budget_amount', 'projects.status')
            ->get();

        $totalBudget = 0;
        $totalSpent = 0;
        $projects = [];

        foreach ($budgetData as $project) {
            $budget = (float)($project->budget_amount ?? 0);
            $spent = (float)$project->spent;
            $remaining = $budget - $spent;
            $percentageUsed = $budget > 0 ? round(($spent / $budget) * 100, 2) : 0;

            $totalBudget += $budget;
            $totalSpent += $spent;

            $projects[] = [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'budget' => $budget,
                'spent' => $spent,
                'remaining' => $remaining,
                'percentage_used' => $percentageUsed,
            ];
        }

        $totalRemaining = $totalBudget - $totalSpent;
        $totalPercentageUsed = $totalBudget > 0 ? round(($totalSpent / $totalBudget) * 100, 2) : 0;

        return [
            'total_budget' => round($totalBudget, 2),
            'total_spent' => round($totalSpent, 2),
            'total_remaining' => round($totalRemaining, 2),
            'total_percentage_used' => $totalPercentageUsed,
            'projects' => $projects,
            'projects_count' => count($projects),
        ];
    }
}

