<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Enums;

enum PayrollReadinessReason: string
{
    case PERIOD_NOT_VALIDATED = 'period_not_validated';
    case SOURCE_EMPTY = 'source_empty';
    case SOURCE_CHANGED = 'source_changed';
    case VALIDATION_BLOCKERS = 'validation_blockers';
    case ACCOUNTING_BLOCKERS = 'accounting_blockers';
    case LOCKED = 'locked';
}
