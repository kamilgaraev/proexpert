<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\DTO;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class EffectiveAssignmentFact
{
    public function __construct(
        public int $assignmentId,
        public int $organizationId,
        public int $employeeId,
        public int $staffUnitId,
        public int $departmentId,
        public int $positionId,
        public ?int $projectId,
        public ?int $workScheduleId,
        public DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validToExclusive,
        public string $fte,
        public int $sourceVersion,
    ) {
        if (min($assignmentId, $organizationId, $employeeId, $staffUnitId, $departmentId, $positionId, $sourceVersion) < 1
            || ($projectId !== null && $projectId < 1)
            || ($workScheduleId !== null && $workScheduleId < 1)
            || ($validToExclusive !== null && $validToExclusive <= $validFrom)
            || BigDecimal::of($fte)->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new InvalidArgumentException('effective_assignment_fact_invalid');
        }
    }

    public function identity(): string
    {
        return implode(':', [
            $this->organizationId,
            $this->employeeId,
            $this->staffUnitId,
            $this->departmentId,
            $this->positionId,
            $this->projectId ?? 'none',
            $this->workScheduleId ?? 'none',
            $this->validFrom->format('Y-m-d'),
            $this->validToExclusive?->format('Y-m-d') ?? 'open',
            $this->fte,
            $this->sourceVersion,
        ]);
    }
}
