<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelSessionAccessService;
use App\BusinessModules\Features\DesignManagement\Services\DesignAccessService;
use App\Exceptions\BusinessLogicException;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Gate;

final readonly class MobileDesignManagementAccess
{
    public function __construct(private MobileProjectAccessResolver $projects, private DesignModelSessionAccessService $access, private AuthorizationService $authorization, private DesignAccessService $documents) {}

    public function documentVersion(User $actor, int $organizationId, int $versionId): DesignArtifactVersion
    {
        $version = DesignArtifactVersion::query()->where('organization_id', $organizationId)->find($versionId);
        if (! $version instanceof DesignArtifactVersion
            || (! in_array($version->file_format, ['pdf', 'pdfa'], true)
                && ! in_array($version->source_mime_type, ['image/png', 'image/jpeg', 'image/webp'], true))) {
            throw new BusinessLogicException(trans_message('design_management.errors.version_not_found'), 404);
        }
        $this->project($actor, $organizationId, (int) $version->project_id, 'design-management.documents.view');
        try {
            $this->documents->assertVersion($version, $actor, 'design-management.documents.view', strictProjectScope: true);
        } catch (DomainException $exception) {
            throw new BusinessLogicException(trans_message('design_management.errors.version_not_found'), 404, $exception);
        }

        return $version;
    }

    public function project(User $actor, int $organizationId, int $projectId, string $permission = 'design-management.models.view'): void
    {
        try {
            $this->projects->assert($actor, $organizationId, $projectId, trans_message('errors.resource_not_found'));
        } catch (DomainException $exception) {
            throw new BusinessLogicException(trans_message('errors.resource_not_found'), 404, $exception);
        }
        if (! Gate::forUser($actor)->allows('access-mobile-app', $organizationId)
            || ! $this->can($actor, $organizationId, $projectId, $permission)) {
            throw new BusinessLogicException(trans_message('errors.forbidden'), 403);
        }
    }

    public function can(User $actor, int $organizationId, int $projectId, string $permission): bool
    {
        return $this->access->canAccessProject($actor, $organizationId, $projectId, $permission)
            && $this->authorization->can($actor, $permission, ['organization_id' => $organizationId, 'project_id' => $projectId, 'strict_project_scope' => true]);
    }

    public function permissions(User $actor, int $organizationId, int $projectId, array $permissions): array
    {
        return $this->access->projectPermissions($actor, $organizationId, $projectId, $permissions);
    }

    public function version(User $actor, int $organizationId, int $versionId, string $permission = 'design-management.models.view'): DesignArtifactVersion
    {
        $version = DesignArtifactVersion::query()->where('organization_id', $organizationId)->where('file_format', 'ifc')
            ->whereHas('artifact', fn ($query) => $query->where('organization_id', $organizationId)
                ->whereColumn('design_artifacts.project_id', 'design_artifact_versions.project_id')
                ->whereHas('package', fn ($packages) => $packages->where('organization_id', $organizationId)
                    ->whereColumn('design_packages.project_id', 'design_artifacts.project_id')))
            ->with(['artifact.package', 'derivatives'])->find($versionId);
        if (! $version instanceof DesignArtifactVersion) {
            throw new BusinessLogicException(trans_message('design_management.errors.version_not_found'), 404);
        }
        $this->project($actor, $organizationId, (int) $version->project_id, $permission);

        return $version;
    }
}
