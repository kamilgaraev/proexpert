<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Contracts;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO\PayrollReadinessSnapshot;

interface PayrollReadinessSnapshotStore
{
    public function append(PayrollReadinessSnapshot $snapshot, iterable $items): void;
}
