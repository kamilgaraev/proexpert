<?php

namespace App\BusinessModules\Features\Procurement\Events;

use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupplierProposalReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public SupplierProposal $proposal
    ) {}
}

