<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts;

interface ProcurementTransactionBoundary
{
    public function isActive(): bool;
}
