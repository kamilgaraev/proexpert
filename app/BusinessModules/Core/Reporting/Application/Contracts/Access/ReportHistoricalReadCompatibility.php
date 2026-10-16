<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Access;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;

interface ReportHistoricalReadCompatibility
{
    public function allows(ReportDefinition $frozen, ReportDefinition $published): bool;
}
