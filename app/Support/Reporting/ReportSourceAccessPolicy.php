<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;

final readonly class ReportSourceAccessPolicy
{
    /**
     * @param  array<int, mixed>  $resources
     * @return array<int, int>|null
     */
    public function allowedIds(array $resources, string $requiredKind): ?array
    {
        if ($resources === []) {
            return null;
        }

        $ids = [];
        foreach ($resources as $resource) {
            if ($resource instanceof ReportScopedResource && $resource->kind === $requiredKind) {
                $ids[$resource->id] = true;
            }
        }

        $result = array_keys($ids);
        sort($result, SORT_NUMERIC);

        return $result;
    }

    public function allows(
        array $resources,
        string $requiredKind,
        int $resourceId,
        ?int $projectId,
        array $allowedProjectIds,
    ): bool {
        if ($allowedProjectIds !== []
            && ($projectId === null || ! in_array($projectId, $allowedProjectIds, true))) {
            return false;
        }
        if ($resources === []) {
            return true;
        }

        foreach ($resources as $resource) {
            if (! $resource instanceof ReportScopedResource
                || $resource->kind !== $requiredKind
                || $resource->id !== $resourceId) {
                continue;
            }
            if ($resource->projectId === null || $resource->projectId === $projectId) {
                return true;
            }
        }

        return false;
    }
}
