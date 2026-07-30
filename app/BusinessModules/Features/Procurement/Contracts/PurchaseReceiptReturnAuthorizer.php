<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Contracts;

use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptLine;
use App\Models\User;

interface PurchaseReceiptReturnAuthorizer
{
    public function canReturn(
        User $actor,
        int $organizationId,
        int $purchaseOrderId,
        int $receiptLineId,
    ): bool;

    public function assertCanReturn(
        User $actor,
        int $organizationId,
        int $purchaseOrderId,
        int $receiptLineId,
    ): PurchaseReceiptLine;
}
