<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Project\UserProjectAccessService;
use DomainException;

final readonly class DesignModelSessionAccessService
{
    public function __construct(
        private AccessController $accessController,
        private AuthorizationService $authorization,
        private UserProjectAccessService $projectAccess,
    ) {
    }

    public function canJoin(User $user, int $sessionId, ?int $organizationId = null): bool
    {
        try {
            $this->requireSession($user, $sessionId, $organizationId);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    public function requireSession(User $user, int $sessionId, ?int $organizationId = null): DesignModelSession
    {
        $session = DesignModelSession::query()->with(['modelSetRevision', 'modelSet'])->find($sessionId);
        if (! $session
            || ($organizationId !== null && (int) $session->organization_id !== $organizationId)
            || ! $this->canAccessProject($user, (int) $session->organization_id, (int) $session->project_id)
            || ! $session->modelSetRevision || ! $session->modelSet
            || (int) $session->modelSetRevision->model_set_id !== (int) $session->model_set_id
            || (int) $session->modelSet->organization_id !== (int) $session->organization_id
            || (int) $session->modelSet->project_id !== (int) $session->project_id) {
            throw new DomainException(trans_message('design_bim.errors.session_access_denied'));
        }
        $versionIds = array_values(array_unique(array_map('intval', $session->modelSetRevision?->version_ids ?? [])));

        if ($versionIds === [] || DesignArtifactVersion::query()
            ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)
            ->whereIn('id', $versionIds)->where('file_format', 'ifc')
            ->whereHas('artifact', fn ($query) => $query->where('organization_id', $session->organization_id)
                ->where('project_id', $session->project_id)->whereHas('package', fn ($packages) => $packages
                    ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)))
            ->count() !== count($versionIds)) {
            throw new DomainException(trans_message('design_bim.errors.session_access_denied'));
        }

        return $session;
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
