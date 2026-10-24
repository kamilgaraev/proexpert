<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Services;

use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final readonly class ChangeRequestStatusCountReadService
{
    public function __construct(
        private AuthorizationService $authorization,
        private OrganizationEntitlementService $entitlements,
        private UserProjectAccessService $projects,
    ) {}

    public function query(User $actor, int $organizationId, Builder $readableChanges): Builder
    {
        $current = User::query()->whereKey($actor->id)->where('is_active', true)
            ->where('current_organization_id', $organizationId)->first();
        if ($current === null || ! $current->organizations()->where('organizations.id', $organizationId)
            ->where('organization_user.is_active', true)->exists()
            || ! $this->entitlements->hasModuleAccess($organizationId, 'change-management')
            || ! $this->authorization->forCurrentChecks()->canCurrent($current, 'change-management.view', ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }
        if (! $readableChanges->getModel() instanceof ChangeRequest) {
            throw new \LogicException('Expected a change request restriction query');
        }
        $projects = $this->projects->queryAccessibleProjects($current, $organizationId, true);

        return ChangeRequest::query()->where('change_management_change_requests.organization_id', $organizationId)
            ->whereIn('change_management_change_requests.id', (clone $readableChanges)->select('change_management_change_requests.id'))
            ->whereIn('change_management_change_requests.project_id', (clone $projects)->select('projects.id'))
            ->select('change_management_change_requests.status')
            ->selectRaw('COUNT(*) AS request_count, SUM(COUNT(*)) OVER () AS total_count, COUNT(*) OVER () AS group_count')
            ->groupBy('change_management_change_requests.status');
    }
}
