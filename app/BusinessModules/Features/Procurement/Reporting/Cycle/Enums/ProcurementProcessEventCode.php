<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums;

enum ProcurementProcessEventCode: string
{
    case REQUEST_CREATED = 'request_created';
    case REQUEST_APPROVED = 'request_approved';
    case SOLICITATION_SENT = 'solicitation_sent';
    case SUPPLIER_RESPONDED = 'supplier_responded';
    case AWARD_DECIDED = 'award_decided';
    case ORDER_SENT = 'order_sent';
    case FIRST_RECEIPT = 'first_receipt';
    case FULLY_RECEIVED = 'fully_received';
    case CANCELLED = 'cancelled';
}
