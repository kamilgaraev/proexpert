<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Enums;

enum ReadinessState: string
{
    case READY = 'ready';
    case BLOCKED = 'blocked';
    case AT_RISK = 'at_risk';
    case UNKNOWN = 'unknown';
    case NOT_APPLICABLE = 'not_applicable';
}
