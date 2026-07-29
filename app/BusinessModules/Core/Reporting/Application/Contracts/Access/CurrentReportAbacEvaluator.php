<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Access;

use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportAuthorizationFacts;
use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportPermissionDecision;

interface CurrentReportAbacEvaluator
{
    public function evaluate(
        int $actorId,
        string $permission,
        CurrentReportAuthorizationFacts $facts,
    ): CurrentReportPermissionDecision;
}
