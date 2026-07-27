<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportReconciliationStatus: string
{
    case MATCHED = 'matched';
    case MISMATCH = 'mismatch';
    case NOT_APPLICABLE = 'not_applicable';
}
