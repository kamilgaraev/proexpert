<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO;

use InvalidArgumentException;

final readonly class WorkforceCapacityFrozenCaptureReceipt
{
    public function __construct(
        public int $requestId,
        public bool $dispatchRequired,
    ) {
        if ($this->requestId < 1) {
            throw new InvalidArgumentException('workforce_capacity_frozen_receipt_invalid');
        }
    }
}
