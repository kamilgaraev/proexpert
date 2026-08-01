<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class WorkforceCapacityCohortKey
{
    public function __construct(
        public int $organizationId,
        public string $asOfDate,
        public string $monthStart,
        public int $staffUnitId,
        public ?int $projectId,
    ) {
        $asOf = DateTimeImmutable::createFromFormat('!Y-m-d', $this->asOfDate);
        $month = DateTimeImmutable::createFromFormat('!Y-m-d', $this->monthStart);

        if ($this->organizationId < 1
            || $this->staffUnitId < 1
            || ($this->projectId !== null && $this->projectId < 1)
            || $asOf === false
            || $asOf->format('Y-m-d') !== $this->asOfDate
            || $month === false
            || $month->format('Y-m-d') !== $this->monthStart
            || $month->format('d') !== '01'
            || $asOf->format('Y-m-01') !== $this->monthStart) {
            throw new InvalidArgumentException('workforce_capacity_cohort_identity_invalid');
        }
    }

    public function canonical(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'as_of_date' => $this->asOfDate,
            'month_start' => $this->monthStart,
            'staff_unit_id' => $this->staffUnitId,
            'project_id' => $this->projectId,
        ];
    }

    public function identity(): string
    {
        return implode(':', [
            $this->organizationId,
            $this->monthStart,
            $this->staffUnitId,
            $this->projectId === null ? 'null' : $this->projectId,
        ]);
    }

    public function sortIdentity(): string
    {
        return sprintf(
            '%020d:%s:%020d:%s:%020d',
            $this->organizationId,
            $this->monthStart,
            $this->staffUnitId,
            $this->projectId === null ? '0' : '1',
            $this->projectId ?? 0,
        );
    }
}
