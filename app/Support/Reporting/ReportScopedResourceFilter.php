<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;

final readonly class ReportScopedResourceFilter
{
    public function ids(ReportScope $scope, array $kinds, array $projectIds): ?array
    {
        $kindSet = array_fill_keys($kinds, true);
        $projectSet = array_fill_keys($projectIds, true);
        $ids = [];
        $restricted = false;

        foreach ($scope->resources as $resource) {
            if (! $resource instanceof ReportScopedResource || ! isset($kindSet[$resource->kind])) {
                continue;
            }
            $restricted = true;
            if ($resource->projectId !== null && ! isset($projectSet[$resource->projectId])) {
                continue;
            }

            $ids[$resource->id] = true;
        }

        if (! $restricted) {
            return null;
        }

        $result = array_map('intval', array_keys($ids));
        sort($result, SORT_NUMERIC);

        return $result;
    }
}
