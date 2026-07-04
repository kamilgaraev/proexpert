<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\DTOs;

use Carbon\CarbonInterface;

final readonly class SafetyComplianceResult
{
    public function __construct(
        public int $employeeId,
        public string $status,
        public string $statusLabel,
        public bool $blocked,
        public bool $expiresSoon,
        public array $requirements,
        public array $blockers,
        public array $warnings,
        public CarbonInterface $checkedAt,
    ) {
    }
}
