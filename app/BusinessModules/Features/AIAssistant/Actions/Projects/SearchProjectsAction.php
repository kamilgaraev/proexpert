<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Projects;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Support\Facades\DB;

class SearchProjectsAction
{
    public function execute(int $organizationId, ?array $params = [], ?User $actor = null): array
    {
        if ($actor === null || ! app(AssistantDataAccessPolicy::class)->canReadDomain($actor, $organizationId, 'projects')
            || ! app(AssistantDataAccessPolicy::class)->canReadDomain($actor, $organizationId, 'finance')) {
            return [];
        }
        $query = DB::table('projects')
            ->leftJoin('completed_works', function($join) {
                $join->on('projects.id', '=', 'completed_works.project_id')
                     ->where('completed_works.status', '=', 'confirmed')
                     ->whereNull('completed_works.deleted_at');
            })
            ->where('projects.organization_id', $organizationId)
            ->whereNull('projects.deleted_at')
            ->whereIn('projects.id', app(UserProjectAccessService::class)
                ->queryAccessibleProjects($actor, $organizationId)->select('projects.id'));

        if (isset($params['status'])) {
            $query->where('projects.status', $params['status']);
        }

        if (isset($params['is_archived'])) {
            $query->where('projects.is_archived', $params['is_archived']);
        }

        if (isset($params['customer'])) {
            $query->where('projects.customer', 'ILIKE', '%' . $params['customer'] . '%');
        }

        if (isset($params['name'])) {
            $query->where('projects.name', 'ILIKE', '%' . $params['name'] . '%');
        }

        if (isset($params['address'])) {
            $query->where('projects.address', 'ILIKE', '%' . $params['address'] . '%');
        }

        $projects = $query
            ->select(
                'projects.id',
                'projects.name',
                'projects.status',
                'projects.address',
                'projects.customer',
                'projects.budget_amount',
                'projects.start_date',
                'projects.end_date',
                'projects.is_archived',
                DB::raw('COALESCE(SUM(completed_works.total_amount), 0) as spent')
            )
            ->groupBy(
                'projects.id',
                'projects.name',
                'projects.status',
                'projects.address',
                'projects.customer',
                'projects.budget_amount',
                'projects.start_date',
                'projects.end_date',
                'projects.is_archived'
            )
            ->orderByDesc('projects.created_at')
            ->limit($params['limit'] ?? 20)
            ->get();

        $result = $projects->map(function($project) {
            $budget = (float)($project->budget_amount ?? 0);
            $spent = (float)$project->spent;
            $remaining = $budget - $spent;
            $percentageUsed = $budget > 0 ? round(($spent / $budget) * 100, 2) : 0;

            return [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'address' => $project->address,
                'customer' => $project->customer,
                'budget' => $budget,
                'spent' => $spent,
                'remaining' => $remaining,
                'percentage_used' => $percentageUsed,
                'start_date' => $project->start_date,
                'end_date' => $project->end_date,
                'is_archived' => (bool)$project->is_archived,
            ];
        })->toArray();

        $statusCounts = $projects->groupBy('status')
            ->map(fn($group) => count($group))
            ->toArray();

        return [
            'total' => count($result),
            'projects' => $result,
            'by_status' => $statusCounts,
            'total_budget' => round($projects->sum('budget_amount'), 2),
            'total_spent' => round($projects->sum('spent'), 2),
        ];
    }
}
