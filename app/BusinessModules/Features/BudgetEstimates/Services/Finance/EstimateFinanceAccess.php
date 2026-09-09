<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Estimate;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;

final class EstimateFinanceAccess
{
    public function __construct(private readonly AuthorizationService $authorization, private readonly UserProjectAccessService $projects) {}

    public function project(User $actor, int $projectId, bool $edit = false): void
    {
        if (! $this->projects->queryAccessibleProjects($actor, (int) $actor->current_organization_id)->whereKey($projectId)->exists()
            || ! $this->can($actor, $projectId, $edit)) {
            throw new AuthorizationException;
        }
    }

    public function estimate(User $actor, int $projectId, int $estimateId, bool $edit = false): Estimate
    {
        $this->project($actor, $projectId, $edit);

        return Estimate::query()->where('organization_id', $actor->current_organization_id)
            ->where('project_id', $projectId)->findOrFail($estimateId);
    }

    public function can(User $actor, int $projectId, bool $edit): bool
    {
        return $this->authorization->can($actor, 'budget-estimates.finance.'.($edit ? 'edit' : 'view'), [
            'context_type' => 'project', 'project_id' => $projectId, 'organization_id' => (int) $actor->current_organization_id,
        ]);
    }
}
