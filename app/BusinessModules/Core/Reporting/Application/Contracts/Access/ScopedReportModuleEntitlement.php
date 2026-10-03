<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Access;

interface ScopedReportModuleEntitlement extends ReportModuleEntitlement
{
    public function forReadScope(): ReportModuleEntitlement;
}
