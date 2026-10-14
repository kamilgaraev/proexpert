<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class ImportAccessContext
{
    public function __construct(public User $actor, public int $organizationId, public int $projectId) {}

    public function authorize(): void
    {
        $project = Project::query()->accessibleByOrganization($this->organizationId)->findOrFail($this->projectId);
        if ($project->is_archived || (int) $this->actor->current_organization_id !== $this->organizationId
            || ! app(UserProjectAccessService::class)->canAccessCurrentOrganizationProject($this->actor, $project)) {
            throw new AuthorizationException;
        }
        AuthorizationContext::getProjectContext($this->projectId, $this->organizationId);
        if (! app(AuthorizationService::class)->canCurrent($this->actor, 'budget-estimates.import', [
                'context_type' => 'project', 'organization_id' => $this->organizationId, 'project_id' => $this->projectId,
                'strict_project_scope' => true,
            ])) {
            throw new AuthorizationException;
        }
    }
}
