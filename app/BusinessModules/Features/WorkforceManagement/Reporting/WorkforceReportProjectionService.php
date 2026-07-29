<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Contracts\WorkforceReportDatabasePort;

final readonly class WorkforceReportProjectionService
{
    public function __construct(private WorkforceReportDatabasePort $database)
    {
    }

    public function materializeCapacity(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        return $this->database->materializeCapacity($scope, $query);
    }

    public function materializeAttendance(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        return $this->database->materializeAttendance($scope, $query);
    }
}
