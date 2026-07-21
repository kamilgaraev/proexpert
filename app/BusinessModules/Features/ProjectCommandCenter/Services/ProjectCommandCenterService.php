<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\Services;

use App\BusinessModules\Features\ProjectCommandCenter\DTO\ProjectCommandCenterData;
use App\Domain\Project\ValueObjects\ProjectContext;
use App\Models\Project;

final class ProjectCommandCenterService
{
    public function build(
        Project $project,
        ProjectContext $projectContext,
        string $period,
        ?string $dateFrom,
        ?string $dateTo,
    ): ProjectCommandCenterData {
        return ProjectCommandCenterData::empty(
            project: $project,
            projectContext: $projectContext,
            period: $period,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            generatedAt: now(),
        );
    }
}
