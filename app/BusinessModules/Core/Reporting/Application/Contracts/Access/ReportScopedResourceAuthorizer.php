<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Access;

use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportAuthorizationFacts;
use App\BusinessModules\Core\Reporting\Application\Access\ReportScopedResourceAccessDecision;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\Models\User;

interface ReportScopedResourceAuthorizer
{
    public function kind(): string;

    public function authorize(
        User $actor,
        int $organizationId,
        ReportScopedResource $resource,
        CurrentReportAuthorizationFacts $facts,
    ): ReportScopedResourceAccessDecision;
}
