<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkflowManagement\DTOs;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class MobileWorkflowTaskPage
{
    public function __construct(
        public LengthAwarePaginator $paginator,
        public array $summary
    ) {
    }
}
