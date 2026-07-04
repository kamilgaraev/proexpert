<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\DTOs;

use Carbon\CarbonInterface;

final readonly class SafetyComplianceRequirementResult
{
    public function __construct(
        public string $code,
        public string $type,
        public string $label,
        public string $status,
        public string $severity,
        public ?string $sourceType = null,
        public ?int $sourceId = null,
        public ?CarbonInterface $validUntil = null,
        public ?string $message = null,
    ) {
    }
}
