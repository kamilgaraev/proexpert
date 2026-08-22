<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Exceptions;

use DomainException;

final class PurchaseReceiptIdempotencyConflictException extends DomainException
{
    public function __construct()
    {
        parent::__construct(trans_message('procurement.purchase_orders.receipt_idempotency_conflict'));
    }
}
