<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Events;

use App\Models\Estimate;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EstimateUpdated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Estimate $estimate
    ) {}
}
