<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\Models\Project;
use App\Models\User;
use DomainException;

final readonly class DesignProjectModelCatalog
{
    public function __construct(private DesignModelSessionAccessService $access) {}

    /** @return array{data:array<int,array<string,mixed>>,pagination:array<string,int>} */
    public function paginate(User $actor, int $organizationId, int $projectId, int $page, string $search): array
    {
        if (! Project::query()->where('organization_id', $organizationId)->whereKey($projectId)->exists()
            || ! $this->access->canAccessProject($actor, $organizationId, $projectId)) {
            throw new DomainException(trans_message('design_bim.errors.forbidden'));
        }
        $term = trim($search);
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $versions = DesignArtifactVersion::query()->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->where('file_format', 'ifc')->whereHas('readyDerivative', fn ($query) => $query->where('organization_id', $organizationId)->where('project_id', $projectId))
            ->whereHas('artifact', fn ($query) => $query->where('organization_id', $organizationId)->where('project_id', $projectId))
            ->whereHas('artifact.package', fn ($query) => $query->where('organization_id', $organizationId)->where('project_id', $projectId))
            ->when($term !== '', fn ($query) => $query->where(function ($query) use ($pattern): void {
                $query->where('title', 'ilike', $pattern)
                    ->orWhereHas('artifact', fn ($artifact) => $artifact->where('title', 'ilike', $pattern))
                    ->orWhereHas('artifact.package', fn ($package) => $package->where('title', 'ilike', $pattern));
            }))
            ->with('artifact.package')->orderByDesc('id')->paginate(25, ['*'], 'page', max(1, $page));

        return [
            'data' => $versions->getCollection()->map(static fn (DesignArtifactVersion $version): array => [
                'id' => $version->id, 'model_id' => $version->artifact_id, 'model_title' => $version->artifact->title,
                'package_id' => $version->artifact->package_id, 'package_title' => $version->artifact->package->title,
                'title' => $version->title, 'version_number' => $version->version_number, 'revision' => $version->revision,
                'derivative_status' => 'ready', 'is_current' => (bool) $version->is_current,
            ])->all(),
            'pagination' => ['current_page' => $versions->currentPage(), 'last_page' => $versions->lastPage(), 'per_page' => $versions->perPage(), 'total' => $versions->total()],
        ];
    }
}
