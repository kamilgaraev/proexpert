<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogView;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;

interface GetReportCatalogAction
{
    public function handle(ReportExecutionContext $context): ReportCatalogView;
}
