<?php

namespace App\BusinessModules\Features\Procurement\Events;

use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PurchaseRequestCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public PurchaseRequest $purchaseRequest
    ) {}
}

