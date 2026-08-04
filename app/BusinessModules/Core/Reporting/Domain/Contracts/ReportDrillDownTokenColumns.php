<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

interface ReportDrillDownTokenColumns
{
    /** @return array<string, string> output column => provider column */
    public function drillDownTokenColumns(): array;
}
