<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Contracts\PayrollReadinessDatabasePort;

final readonly class PayrollReadinessProjectionService
{
    public function __construct(private PayrollReadinessDatabasePort $database)
    {
    }

    public function materialize(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        return $this->database->materialize($scope, $query);
    }
}
