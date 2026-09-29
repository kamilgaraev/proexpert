<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Projects;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantLegacyFinancialRead;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Support\Facades\DB;

class GetProjectBudgetAction
{
    public function execute(int $organizationId, ?array $params = [], ?User $actor = null): array
    {
        if ($actor === null || ! app(AssistantDataAccessPolicy::class)->canReadDomain($actor, $organizationId, 'finance')) {
            return [];
        }
        $projectId = $params['project_id'] ?? null;
        $finance = app(AssistantLegacyFinancialRead::class);

        $query = DB::table('projects')
            ->where('projects.organization_id', $organizationId)
            ->where('projects.is_archived', false)
            ->whereNull('projects.deleted_at')
            ->whereIn('projects.id', app(UserProjectAccessService::class)
                ->queryAccessibleProjects($actor, $organizationId)->select('projects.id'));

        if ($projectId) {
            $query->where('projects.id', $projectId);
        }

        $budgetData = $query->get();

        $totalBudget = [];
        $totalSpent = [];
        $projects = [];

        foreach ($budgetData as $project) {
            $budget = $finance->projectBudget($actor, $organizationId, $project->budget_amount);
            $source = $finance->projectSpent($actor, $organizationId, (int) $project->id);
            $spent = $source['spent'];
            $remaining = AssistantLegacyFinancialRead::subtract($budget, $spent);
            $percentageUsed = AssistantLegacyFinancialRead::percentage($spent, $budget);

            $totalBudget[] = $budget;
            $totalSpent[] = $spent;

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

        $totalBudget = $projects === [] || in_array(null, $totalBudget, true) ? null : AssistantLegacyFinancialRead::sum($totalBudget);
        $totalSpent = $projects === [] || in_array(null, $totalSpent, true) ? null : AssistantLegacyFinancialRead::sum($totalSpent);
        $totalRemaining = AssistantLegacyFinancialRead::subtract($totalBudget, $totalSpent);
        $totalPercentageUsed = AssistantLegacyFinancialRead::percentage($totalSpent, $totalBudget);

        return [
            'total_budget' => $totalBudget,
            'total_spent' => $totalSpent,
            'total_remaining' => $totalRemaining,
            'total_percentage_used' => $totalPercentageUsed,
            'projects' => $projects,
            'projects_count' => count($projects),
        ];
    }
}
