<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Domain\Project\ValueObjects\ProjectContext;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\ProjectContextService;

class AdminProjectAccessService
{
    public function __construct(
        private readonly ProjectContextService $projectContextService,
    ) {
    }

    public function getProjectContext(Project $project, User $user): ?ProjectContext
    {
        $organization = $this->resolveCurrentOrganization($user);

        if (!$organization instanceof Organization) {
            return null;
        }

        try {
            return $this->projectContextService->getContext($project, $organization);
        } catch (\Throwable) {
            return null;
        }
    }

    public function canAccessProject(Project $project, User $user): bool
    {
        return $this->getProjectContext($project, $user) instanceof ProjectContext;
    }

    public function canViewProjectDashboard(Project $project, User $user): bool
    {
        $context = $this->getProjectContext($project, $user);

        if (!$context instanceof ProjectContext) {
            return false;
        }

        return $context->roleConfig->canViewFinances;
    }

    public function resolveCurrentOrganizationId(User $user): ?int
    {
        return $this->resolveCurrentOrganization($user)?->id;
    }

    private function resolveCurrentOrganization(User $user): ?Organization
    {
        $organizationId = (int) ($user->current_organization_id ?? 0);

        if ($organizationId <= 0) {
            return null;
        }

        $organization = $user->relationLoaded('organization')
            ? $user->organization
            : Organization::query()->find($organizationId);

        return $organization instanceof Organization ? $organization : null;
    }
}
