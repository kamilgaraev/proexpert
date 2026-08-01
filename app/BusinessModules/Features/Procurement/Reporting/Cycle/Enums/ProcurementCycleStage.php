<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums;

enum ProcurementCycleStage: string
{
    case REQUEST_APPROVAL = 'request_approval';
    case SOLICITATION = 'solicitation';
    case SUPPLIER_RESPONSE = 'supplier_response';
    case AWARD = 'award';
    case ORDER_DISPATCH = 'order_dispatch';
    case FIRST_RECEIPT = 'first_receipt';
    case FULL_RECEIPT = 'full_receipt';
}
