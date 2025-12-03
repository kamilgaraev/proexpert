<?php

namespace App\BusinessModules\Features\Procurement\Events;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PurchaseOrderSent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public PurchaseOrder $purchaseOrder
    ) {}
}

