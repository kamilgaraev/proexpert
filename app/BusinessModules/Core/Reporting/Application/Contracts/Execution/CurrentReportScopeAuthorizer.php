<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Application\Access\ReportCatalogAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use DateTimeZone;

interface CurrentReportScopeAuthorizer extends CurrentReportExactManyAuthorizer
{
    /** @param list<CurrentReportAuthorizationTarget> $targets */
    public function authorizeCatalog(
        int $actorId,
        int $organizationId,
        DateTimeZone $timezone,
        array $targets,
    ): ReportCatalogAuthorization;

    public function authorizeForOrganization(
        int $actorId,
        int $organizationId,
        DateTimeZone $timezone,
        CurrentReportAuthorizationTarget $target,
    ): CurrentReportAuthorization;

    public function authorizeExact(
        int $actorId,
        ReportScope $requestedScope,
        CurrentReportAuthorizationTarget $target,
    ): CurrentReportAuthorization;
}
