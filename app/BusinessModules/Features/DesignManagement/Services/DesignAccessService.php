<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

final readonly class DesignAccessService
{
    public function __construct(
        private AuthorizationService $authorization,
        private UserProjectAccessService $projectAccess,
    ) {}

    public function requireActor(User|int|null $actor = null): User
    {
        if (is_int($actor)) {
            $actor = User::query()->find($actor);
            if ($actor === null) {
                throw new DomainException(trans_message('design_bim.errors.forbidden'));
            }
        }
        $actor ??= auth()->user();

        if (! $actor instanceof User) {
            throw new DomainException(trans_message('design_bim.errors.forbidden'));
        }

        return $actor;
    }

    public function canAccessProject(User $actor, int $organizationId, int $projectId, string $permission, bool $strictProjectScope = false): bool
    {
        $context = ['organization_id' => $organizationId, 'project_id' => $projectId];
        if ($strictProjectScope) {
            $context['strict_project_scope'] = true;
        }
        return $actor->belongsToOrganization($organizationId)
            && $this->projectAccess->queryAccessibleProjects($actor, $organizationId)->where('projects.id', $projectId)->exists()
            && $this->authorization->can($actor, $permission, $context);
    }

    public function accessibleProjectIds(User $actor, int $organizationId, string $permission): Builder
    {
        $query = $this->projectAccess->queryAccessibleProjects($actor, $organizationId);

        if (! $actor->belongsToOrganization($organizationId)) {
            return $query->whereRaw('1 = 0');
        }

        $allowed = (clone $query)->pluck('projects.id')->filter(
            fn ($projectId): bool => $this->authorization->can($actor, $permission, [
                'organization_id' => $organizationId,
                'project_id' => (int) $projectId,
            ])
        );

        return $query->whereIn('projects.id', $allowed)->select('projects.id');
    }

    public function assertPackage(DesignPackage $package, User $actor, string $permission): void
    {
        if (! $this->canAccessProject($actor, (int) $package->organization_id, (int) $package->project_id, $permission)) {
            throw new DomainException(trans_message('design_management.errors.package_not_found'));
        }
    }

    public function assertVersion(DesignArtifactVersion $version, User $actor, string $permission, bool $modelOnly = false, bool $strictProjectScope = false): void
    {
        $version->loadMissing('artifact.package', 'artifact.section');
        $artifact = $version->artifact;
        $package = $artifact?->package;
        $section = $artifact?->section;
        $artifactType = $artifact?->artifact_type;
        $artifactType = $artifactType instanceof \BackedEnum ? $artifactType->value : $artifactType;

        if ($artifact === null || ! $package instanceof DesignPackage
            || (int) $artifact->organization_id !== (int) $version->organization_id
            || (int) $artifact->project_id !== (int) $version->project_id
            || (int) $package->organization_id !== (int) $version->organization_id
            || (int) $package->project_id !== (int) $version->project_id
            || ($artifact->section_id !== null && ($section === null
                || (int) $section->organization_id !== (int) $version->organization_id
                || (int) $section->project_id !== (int) $version->project_id
                || (int) $section->package_id !== (int) $package->id))
            || ($modelOnly && ($artifactType !== 'model' || $version->file_format !== 'ifc'))
            || ! $this->canAccessProject($actor, (int) $version->organization_id, (int) $version->project_id, $permission, $strictProjectScope)) {
            throw new DomainException(trans_message('design_management.errors.version_not_found'));
        }
    }
}
