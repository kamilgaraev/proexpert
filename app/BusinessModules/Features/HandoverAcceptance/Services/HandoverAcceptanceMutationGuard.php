<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Services;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;

final readonly class HandoverAcceptanceMutationGuard
{
    public function __construct(
        private AuthorizationService $authorization,
        private UserProjectAccessService $projectAccess,
    ) {}

    public function assertActor(AcceptanceScope $scope, int $actorId, string $permission): void
    {
        $actor = User::query()->find($actorId);
        $organizationId = (int) $scope->organization_id;
        $project = $scope->project;
        if ($actor === null || (int) $actor->current_organization_id !== $organizationId
            || ! $actor->belongsToOrganization($organizationId) || $project === null
            || ! $this->projectAccess->canAccessProject($actor, $project, $organizationId)) {
            throw new BusinessLogicException(trans_message('handover_acceptance.errors.scope_not_found'), 404);
        }
        if (! $this->authorization->can($actor, $permission, [
            'organization_id' => $organizationId,
            'project_id' => (int) $scope->project_id,
            'strict_project_scope' => true,
        ])) {
            throw new BusinessLogicException(trans_message('handover_acceptance.errors.forbidden'), 403);
        }
    }
}
