<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Enums;

enum ProcurementAwardCompleteness: string
{
    case COMPLETE = 'complete';
    case NOT_COMPARABLE = 'not_comparable';
    case GAP = 'gap';
    case LEGACY_UNVERIFIED = 'legacy_unverified';
}
