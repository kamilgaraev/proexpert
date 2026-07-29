<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class WorkforceAdmissionReadiness
{
    public function __construct(
        public string $status,
        public int $eligibleCount,
        public int $projectedCount,
        public int $gapCount,
        public int $unknownCount,
        public ?string $inputHash,
        public ?string $outputHash,
        public ?DateTimeImmutable $verifiedAt,
    ) {
        if (! in_array($status, ['ready', 'partial', 'unavailable'], true)
            || min($eligibleCount, $projectedCount, $gapCount, $unknownCount) < 0) {
            throw new InvalidArgumentException('workforce_admission_readiness_invalid');
        }
    }
}
