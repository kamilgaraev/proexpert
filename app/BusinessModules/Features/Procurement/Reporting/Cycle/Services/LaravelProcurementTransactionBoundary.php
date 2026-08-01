<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementTransactionBoundary;
use Illuminate\Support\Facades\DB;

final class LaravelProcurementTransactionBoundary implements ProcurementTransactionBoundary
{
    public function isActive(): bool
    {
        return DB::transactionLevel() > 0;
    }
}
