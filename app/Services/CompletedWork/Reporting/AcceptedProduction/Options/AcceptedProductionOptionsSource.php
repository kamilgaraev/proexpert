<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Options;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;

interface AcceptedProductionOptionsSource
{
    public function snapshot(ReportScope $scope, ReportQuery $query): AcceptedProductionOptionsSourceSnapshot;
}
