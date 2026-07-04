<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\DTOs;

use Carbon\CarbonInterface;

final readonly class SafetyComplianceContext
{
    public function __construct(
        public int $organizationId,
        public int $employeeId,
        public ?int $userId = null,
        public ?int $projectId = null,
        public ?int $workTypeId = null,
        public ?string $workCategory = null,
        public ?CarbonInterface $date = null,
        public ?string $positionName = null,
        public ?int $permitId = null,
        public ?int $workOrderLineId = null,
    ) {
    }
}
