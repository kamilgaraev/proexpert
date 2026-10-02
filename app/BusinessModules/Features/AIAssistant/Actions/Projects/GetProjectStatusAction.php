<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Projects;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantLegacyFinancialRead;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Support\Facades\DB;

class GetProjectStatusAction
{
    public function execute(int $organizationId, ?array $params = [], ?User $actor = null): array
    {
        if ($actor === null || ! app(AssistantDataAccessPolicy::class)->canReadDomain($actor, $organizationId, 'projects')
            || ! app(AssistantDataAccessPolicy::class)->canReadDomain($actor, $organizationId, 'finance')) {
            return [];
        }

        $projectId = $params['project_id'] ?? null;
        $finance = app(AssistantLegacyFinancialRead::class);
        $accessibleProjects = app(UserProjectAccessService::class)
            ->queryAccessibleProjects($actor, $organizationId)->select('projects.id');

        $statusCounts = DB::table('projects')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereIn('id', $accessibleProjects)
            ->select(
                'status',
                'is_archived',
                DB::raw('COUNT(*) as count')
            )
            ->when($projectId, function ($query, $id) {
                return $query->where('id', $id);
            })
            ->groupBy('status', 'is_archived')
            ->get();

        $total = 0;
        $active = 0;
        $completed = 0;
        $paused = 0;
        $cancelled = 0;
        $archived = 0;

        foreach ($statusCounts as $row) {
            $count = (int) $row->count;
            $total += $count;

            if ($row->is_archived) {
                $archived += $count;
            } else {
                switch ($row->status) {
                    case 'active':
                        $active += $count;
                        break;
                    case 'completed':
                        $completed += $count;
                        break;
                    case 'paused':
                        $paused += $count;
                        break;
                    case 'cancelled':
                        $cancelled += $count;
                        break;
                }
            }
        }

        $projects = DB::table('projects')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereIn('id', $accessibleProjects)
            ->when($projectId, function ($query, $id) {
                return $query->where('id', $id);
            })
            ->select(
                'id',
                'name',
                'status',
                'start_date',
                'end_date',
                'budget_amount',
                'is_archived'
            )
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(function ($project) use ($finance, $actor, $organizationId) {
                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'status' => $project->status,
                    'start_date' => $project->start_date,
                    'end_date' => $project->end_date,
                    'budget' => $finance->projectBudget($actor, $organizationId, $project->budget_amount),
                    'is_archived' => (bool) $project->is_archived,
                ];
            })
            ->toArray();

        return [
            'total_projects' => $total,
            'active' => $active,
            'completed' => $completed,
            'paused' => $paused,
            'cancelled' => $cancelled,
            'archived' => $archived,
            'projects' => $projects,
        ];
    }
}
