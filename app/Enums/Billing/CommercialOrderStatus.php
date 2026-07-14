<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum CommercialOrderStatus: string
{
    case Draft = 'draft';
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Canceled = 'canceled';
    case Refunded = 'refunded';
}
