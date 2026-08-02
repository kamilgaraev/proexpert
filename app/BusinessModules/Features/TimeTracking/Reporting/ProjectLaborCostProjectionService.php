<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\TimeTracking\Reporting\Contracts\ProjectLaborCostDatabasePort;

final readonly class ProjectLaborCostProjectionService
{
    public function __construct(private ProjectLaborCostDatabasePort $database)
    {
    }

    public function materialize(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        return $this->database->materialize($scope, $query);
    }
}
