<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportSourceReadiness: string
{
    case READY = 'ready';
    case PARTIAL = 'partial';
    case AGGREGATION_REQUIRED = 'aggregation_required';
    case EVENT_REQUIRED = 'event_required';
    case BLOCKED_BY_SOURCE = 'blocked_by_source';
}
