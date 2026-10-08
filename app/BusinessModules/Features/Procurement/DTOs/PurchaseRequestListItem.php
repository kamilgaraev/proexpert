<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\DTOs;

use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;

final readonly class PurchaseRequestListItem
{
    public function __construct(
        public PurchaseRequest $purchaseRequest,
        public ProcurementLifecycleSummary $workflowSummary,
    ) {}
}
