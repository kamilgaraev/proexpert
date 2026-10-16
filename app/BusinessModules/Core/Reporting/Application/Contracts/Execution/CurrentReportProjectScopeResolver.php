<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use DateTimeZone;

interface CurrentReportProjectScopeResolver
{
    public function resolveForProject(
        int $actorId,
        int $organizationId,
        int $projectId,
        DateTimeZone $timezone,
    ): ReportScope;
}
