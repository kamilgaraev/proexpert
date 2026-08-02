<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Reporting;

enum SettlementAgingBucket: string
{
    case NOT_DUE = 'not_due';
    case DAYS_1_30 = 'days_1_30';
    case DAYS_31_60 = 'days_31_60';
    case DAYS_61_90 = 'days_61_90';
    case OVER_90 = 'over_90';
}
