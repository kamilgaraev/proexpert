<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCycleSnapshotRequest;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCycleSourceRead;

interface ProcurementCycleSourceReader
{
    public function read(ProcurementCycleSnapshotRequest $request, callable $consumeLine): ProcurementCycleSourceRead;
}
