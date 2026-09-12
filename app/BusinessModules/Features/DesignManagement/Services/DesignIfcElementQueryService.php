<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class DesignIfcElementQueryService
{
    public function __construct(private readonly DesignModelSessionAccessService $projectAccess) {}

    public function element(User $actor, int $organizationId, int $versionId, int $expressId): DesignIfcModelElement
    {
        $version = $this->version($actor, $organizationId, $versionId);
        $element = DesignIfcModelElement::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $version->project_id)
            ->where('version_id', $versionId)
            ->where('express_id', $expressId)
            ->first();

        if (! $element instanceof DesignIfcModelElement) {
            throw new DomainException(trans_message('design_ifc.errors.element_not_found'));
        }

        return $element;
    }

    public function paginate(User $actor, int $organizationId, int $versionId, int $perPage, ?string $search = null): LengthAwarePaginator
    {
        $version = $this->version($actor, $organizationId, $versionId);
        return DesignIfcModelElement::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $version->project_id)
            ->where('version_id', $versionId)
            ->when(trim((string) $search) !== '', static function ($query) use ($search): void {
                $term = trim((string) $search);
                $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $query->where(static function ($scope) use ($term, $pattern): void {
                    $scope->where('name', 'ilike', $pattern)->orWhere('category', 'ilike', $pattern)->orWhere('global_id', 'ilike', $pattern);
                    if (ctype_digit($term) && strlen($term) <= 16) {
                        $scope->orWhere('express_id', (int) $term);
                    }
                });
            })
            ->orderBy('express_id')
            ->paginate($perPage);
    }

    public function payload(DesignIfcModelElement $element): array
    {
        return [
            'model_id' => $element->version_id,
            'version_id' => $element->version_id,
            'element_id' => $element->express_id,
            'express_id' => $element->express_id,
            'global_id' => $element->global_id,
            'category' => $element->category,
            'name' => $element->name,
            'properties' => $element->properties ?? [],
            'classifications' => $element->classifications ?? [],
            'updated_at' => $element->updated_at?->toIso8601String(),
        ];
    }

    private function version(User $actor, int $organizationId, int $versionId): DesignArtifactVersion
    {
        $version = DesignArtifactVersion::query()
            ->where('organization_id', $organizationId)
            ->whereKey($versionId)
            ->first();
        if (! $version instanceof DesignArtifactVersion
            || ! $this->projectAccess->canAccessProject($actor, $organizationId, (int) $version->project_id)) {
            throw new DomainException(trans_message('design_ifc.errors.element_not_found'));
        }

        return $version;
    }
}
