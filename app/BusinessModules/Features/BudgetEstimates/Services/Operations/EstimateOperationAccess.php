<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Operations;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Estimate;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;

final class EstimateOperationAccess
{
    public function __construct(private readonly AuthorizationService $authorization, private readonly UserProjectAccessService $projects)
    {
    }

    public function project(User $actor, int $organizationId, int $projectId): Project
    {
        $project = Project::query()->where('organization_id', $organizationId)->findOrFail($projectId);
        $context = ['context_type' => 'organization', 'organization_id' => $organizationId];
        if ((int) $actor->current_organization_id !== $organizationId
            || ! $this->authorization->can($actor, 'budget-estimates.create', $context)
            || ! $this->projects->canAccessProject($actor, $project, $organizationId)) {
            throw new AuthorizationException(trans_message('estimate_operations.access_denied'));
        }

        return $project;
    }

    public function source(User $actor, int $organizationId, int $projectId, int $id): Estimate
    {
        $this->project($actor, $organizationId, $projectId);
        $estimate = Estimate::query()->where('organization_id', $organizationId)->where('project_id', $projectId)->findOrFail($id);
        $context = ['context_type' => 'organization', 'organization_id' => $organizationId];
        if (! $this->authorization->can($actor, 'budget-estimates.view', $context)
            && ! $this->authorization->can($actor, 'budget-estimates.view_all', $context)) {
            throw new AuthorizationException(trans_message('estimate_operations.access_denied'));
        }

        return $estimate;
    }
}
