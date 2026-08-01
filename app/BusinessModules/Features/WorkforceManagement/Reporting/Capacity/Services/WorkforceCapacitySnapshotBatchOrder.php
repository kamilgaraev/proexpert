<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCohortKey;
use LogicException;

final readonly class WorkforceCapacitySnapshotBatchOrder
{
    public function assertKeys(array $keys): void
    {
        $previous = null;
        foreach ($keys as $key) {
            if (! $key instanceof WorkforceCapacityCohortKey) {
                throw new LogicException('workforce_capacity_batch_order_invalid');
            }

            $current = $key->sortIdentity();
            if ($previous !== null && strcmp($previous, $current) >= 0) {
                throw new LogicException('workforce_capacity_batch_order_invalid');
            }

            $previous = $current;
        }
    }
}
