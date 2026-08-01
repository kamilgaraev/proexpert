<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class WorkforceCapacityLifecycleCaptureDraft
{
    public function __construct(
        public int $requestId,
        public int $organizationId,
        public int $employeeId,
        public string $dismissalDate,
        public int $stagedRangeCount,
        public bool $preparationRequired = true,
        public bool $dispatchRequired = false,
    ) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $this->dismissalDate);
        if ($this->requestId < 1
            || $this->organizationId < 1
            || $this->employeeId < 1
            || $this->stagedRangeCount < 0
            || ($this->preparationRequired && $this->dispatchRequired)
            || $date === false
            || $date->format('Y-m-d') !== $this->dismissalDate) {
            throw new InvalidArgumentException('workforce_capacity_lifecycle_draft_invalid');
        }
    }
}
