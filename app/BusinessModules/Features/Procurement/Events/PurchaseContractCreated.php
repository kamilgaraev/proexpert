<?php

namespace App\BusinessModules\Features\Procurement\Events;

use App\Models\Contract;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PurchaseContractCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Contract $contract,
        public PurchaseOrder $purchaseOrder
    ) {}
}

