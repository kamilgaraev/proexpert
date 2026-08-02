<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO;

use DomainException;

final readonly class ManagementPnlAllocation
{
    public function __construct(
        public ?int $projectId,
        public ?int $responsibilityCenterId,
        public ?int $budgetArticleId,
        public int $basisPoints,
    ) {
        if ($basisPoints < 1 || $basisPoints > 10000) {
            throw new DomainException('management_pnl_allocation_invalid');
        }
    }
}
