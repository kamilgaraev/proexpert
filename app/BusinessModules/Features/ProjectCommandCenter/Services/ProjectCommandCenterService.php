<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\Services;

use App\BusinessModules\Features\ProjectCommandCenter\DTO\ProjectCommandCenterData;
use App\Models\Project;

final class ProjectCommandCenterService
{
    public function build(
        Project $project,
        string $period,
        ?string $dateFrom,
        ?string $dateTo,
    ): ProjectCommandCenterData {
        return ProjectCommandCenterData::empty(
            project: $project,
            period: $period,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            generatedAt: now(),
        );
    }
}
