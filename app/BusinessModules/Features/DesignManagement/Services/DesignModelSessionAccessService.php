<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Project\UserProjectAccessService;

final readonly class DesignModelSessionAccessService
{
    public function __construct(
        private AccessController $accessController,
        private AuthorizationService $authorization,
        private UserProjectAccessService $projectAccess,
    ) {
    }

    public function canJoin(User $user, int $sessionId): bool
    {
        $session = DesignModelSession::query()->with('modelSetRevision')->find($sessionId);
        if (! $session || ! $this->canAccessProject($user, (int) $session->organization_id, (int) $session->project_id)) {
            return false;
        }
        $versionIds = array_values(array_unique(array_map('intval', $session->modelSetRevision?->version_ids ?? [])));

        return $versionIds !== [] && DesignArtifactVersion::query()
            ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)
            ->whereIn('id', $versionIds)->where('file_format', 'ifc')
            ->whereHas('artifact', fn ($query) => $query->where('organization_id', $session->organization_id)
                ->where('project_id', $session->project_id)->whereHas('package', fn ($packages) => $packages
                    ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)))
            ->count() === count($versionIds);
    }

    public function canAccessProject(User $user, int $organizationId, int $projectId, string $permission = 'design-management.models.view'): bool
    {
        if (! $user->belongsToOrganization($organizationId) || ! $this->accessController->hasModuleAccess($organizationId, 'design-management')) {
            return false;
        }

        $projectMembership = $this->projectAccess->queryAccessibleProjects($user, $organizationId)
            ->where('projects.id', $projectId)
            ->exists();

        return $projectMembership && $this->authorization->can($user, $permission, [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
        ]);
    }
}
