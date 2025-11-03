<?php

namespace App\BusinessModules\Features\BudgetEstimates\Events;

use App\Models\Estimate;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EstimateCreated
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly Estimate $estimate
    ) {}
}

