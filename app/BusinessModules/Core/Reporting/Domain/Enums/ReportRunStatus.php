<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportRunStatus: string
{
    case QUEUED = 'queued';
    case MATERIALIZING = 'materializing';
    case READY = 'ready';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
}
