<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\DTOs;

use Illuminate\Pagination\LengthAwarePaginator;

final readonly class MobileTimeEntryPage
{
    public function __construct(
        public LengthAwarePaginator $paginator,
        public array $summary
    ) {
    }
}
