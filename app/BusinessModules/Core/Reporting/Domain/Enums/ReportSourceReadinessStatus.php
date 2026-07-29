<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportSourceReadinessStatus: string
{
    case READY = 'ready';
    case PARTIAL = 'partial';
    case STALE = 'stale';
    case UNAVAILABLE = 'unavailable';
}
