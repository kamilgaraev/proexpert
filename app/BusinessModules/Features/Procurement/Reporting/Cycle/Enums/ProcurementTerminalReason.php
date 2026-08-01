<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums;

enum ProcurementTerminalReason: string
{
    case REQUEST_REJECTED = 'request_rejected';
    case REQUEST_CANCELLED = 'request_cancelled';
    case ORDER_CANCELLED = 'order_cancelled';
}
