<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessTransition;

interface ProcurementProcessEventStore
{
    public function append(ProcurementProcessTransition $transition): void;
}
