<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportDataClassification: string
{
    case STANDARD = 'standard';
    case SENSITIVE = 'sensitive';
}
