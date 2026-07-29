<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportDeliveryReadiness: string
{
    case NOT_IMPLEMENTED = 'not_implemented';
    case VERIFIED = 'verified';
}
