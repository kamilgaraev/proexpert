<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO;

use InvalidArgumentException;

final readonly class WorkforceCapacityFrozenCaptureRequestState
{
    public function __construct(
        public int $requestId,
        public bool $preparationRequired,
        public bool $dispatchRequired,
    ) {
        if ($this->requestId < 1 || ($this->preparationRequired && $this->dispatchRequired)) {
            throw new InvalidArgumentException('workforce_capacity_frozen_request_state_invalid');
        }
    }
}
