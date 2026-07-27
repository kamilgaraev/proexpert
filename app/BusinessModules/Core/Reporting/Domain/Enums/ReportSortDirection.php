<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportSortDirection: string
{
    case ASC = 'asc';
    case DESC = 'desc';
}
