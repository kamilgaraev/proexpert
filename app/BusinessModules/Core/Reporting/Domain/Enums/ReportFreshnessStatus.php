<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportFreshnessStatus: string
{
    case FRESH = 'fresh';
    case STALE = 'stale';
    case PARTIAL = 'partial';
    case UNAVAILABLE = 'unavailable';
}
