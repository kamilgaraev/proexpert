<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class WorkforceCapacityRangeDescriptor
{
    public function __construct(
        public int $organizationId,
        public int $staffUnitId,
        public ?int $projectId,
        public string $fromMonth,
        public string $throughMonth,
    ) {
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $this->fromMonth);
        $through = DateTimeImmutable::createFromFormat('!Y-m-d', $this->throughMonth);
        if ($this->organizationId < 1
            || $this->staffUnitId < 1
            || ($this->projectId !== null && $this->projectId < 1)
            || $from === false
            || $through === false
            || $from->format('Y-m-d') !== $this->fromMonth
            || $through->format('Y-m-d') !== $this->throughMonth
            || $from->format('d') !== '01'
            || $through->format('d') !== '01'
            || $through < $from) {
            throw new InvalidArgumentException('workforce_capacity_range_descriptor_invalid');
        }
    }

    public function canonical(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'staff_unit_id' => $this->staffUnitId,
            'project_id' => $this->projectId,
            'from_month' => $this->fromMonth,
            'through_month' => $this->throughMonth,
        ];
    }

    public static function fromArray(array $value): self
    {
        return new self(
            (int) ($value['organization_id'] ?? 0),
            (int) ($value['staff_unit_id'] ?? 0),
            isset($value['project_id']) ? (int) $value['project_id'] : null,
            (string) ($value['from_month'] ?? ''),
            (string) ($value['through_month'] ?? ''),
        );
    }
}
