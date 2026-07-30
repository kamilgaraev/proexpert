<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;

interface ManagementPnlComponentSource
{
    public function componentCode(): string;

    public function snapshots(ReportScope $scope, ReportQuery $query): iterable;
}
