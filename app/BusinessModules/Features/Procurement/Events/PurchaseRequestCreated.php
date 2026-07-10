<?php

namespace App\BusinessModules\Features\Procurement\Events;

use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PurchaseRequestCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public PurchaseRequest $purchaseRequest
    ) {}
}

