<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\DTOs;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class MobileBudgetEstimatePage
{
    public function __construct(
        public LengthAwarePaginator $paginator,
        public array $summary,
    ) {
    }
}
