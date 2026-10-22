<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Services;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceFinding;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final readonly class HandoverAcceptanceControlReadService
{
    public function __construct(
        private AuthorizationService $authorization,
        private OrganizationEntitlementService $entitlements,
        private UserProjectAccessService $projects,
    ) {}

    public function query(User $actor, int $organizationId, Builder $readableScopes): Builder
    {
        $current = User::query()->whereKey($actor->id)->where('is_active', true)
            ->where('current_organization_id', $organizationId)->first();
        if ($current === null || ! $current->organizations()->where('organizations.id', $organizationId)
            ->where('organization_user.is_active', true)->exists()
            || ! $this->entitlements->hasModuleAccess($organizationId, 'handover-acceptance')
            || ! $this->authorization->forCurrentChecks()->canCurrent($current, 'handover-acceptance.view', ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }
        if (! $readableScopes->getModel() instanceof AcceptanceScope) {
            throw new \LogicException('Expected an acceptance scope restriction query');
        }
        $projects = $this->projects->queryAccessibleProjects($current, $organizationId, true);
        $findings = AcceptanceFinding::query()->selectRaw('COUNT(*)')
            ->where('acceptance_findings.organization_id', $organizationId)
            ->whereColumn('acceptance_findings.project_id', 'acceptance_scopes.project_id')
            ->whereColumn('acceptance_findings.acceptance_scope_id', 'acceptance_scopes.id')
            ->where('acceptance_findings.status', 'open');

        return AcceptanceScope::query()->where('acceptance_scopes.organization_id', $organizationId)
            ->whereIn('acceptance_scopes.id', (clone $readableScopes)->select('acceptance_scopes.id'))
            ->whereIn('acceptance_scopes.project_id', (clone $projects)->select('projects.id'))
            ->whereNotIn('acceptance_scopes.status', ['accepted', 'handed_over'])
            ->select(['acceptance_scopes.id', 'acceptance_scopes.project_id', 'acceptance_scopes.title',
                'acceptance_scopes.status', 'acceptance_scopes.planned_acceptance_date'])
            ->selectSub((clone $projects)->select('projects.name')
                ->whereColumn('projects.id', 'acceptance_scopes.project_id')->limit(1), 'project_name')
            ->selectSub($findings, 'open_findings_count')
            ->selectRaw('COUNT(*) OVER () AS matched_count');
    }
}
