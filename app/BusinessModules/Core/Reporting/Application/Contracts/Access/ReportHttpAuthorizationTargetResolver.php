<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Access;

use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;

interface ReportHttpAuthorizationTargetResolver
{
    public function createRun(string $reportCode): CurrentReportAuthorizationTarget;

    public function run(string $runId, ReportOperation $operation): CurrentReportAuthorizationTarget;

    public function createExport(string $runId, ?string $format): CurrentReportAuthorizationTarget;

    public function export(string $exportId, ReportOperation $operation): CurrentReportAuthorizationTarget;

    /** @return list<CurrentReportAuthorizationTarget> */
    public function catalog(): array;
}
