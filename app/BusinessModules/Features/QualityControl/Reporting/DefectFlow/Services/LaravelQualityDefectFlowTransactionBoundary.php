<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services;

use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Contracts\QualityDefectFlowTransactionBoundary;
use Illuminate\Support\Facades\DB;

final class LaravelQualityDefectFlowTransactionBoundary implements QualityDefectFlowTransactionBoundary
{
    public function isActive(): bool
    {
        return DB::connection()->transactionLevel() > 0;
    }
}
