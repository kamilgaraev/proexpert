<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;

interface CurrentReportExactManyAuthorizer
{
    /**
     * @param list<CurrentReportAuthorizationTarget> $targets
     * @return list<CurrentReportAuthorization>
     */
    public function authorizeExactMany(
        int $actorId,
        ReportScope $requestedScope,
        array $targets,
    ): array;
}
