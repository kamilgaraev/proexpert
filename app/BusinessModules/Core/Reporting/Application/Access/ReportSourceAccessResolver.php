<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;

interface ReportSourceAccessResolver
{
    public function canAccess(ReportExecutionContext $context, ReportSourceRef $source): bool;
}
