<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class WorkforceCapacityFrozenSourceProjection
{
    private const TYPES = [
        'staff_unit',
        'assignment',
        'employee_lifecycle',
        'schedule',
        'schedule_day',
        'absence',
        'business_trip',
    ];

    public function __construct(
        public string $cohortIdentity,
        public string $sourceType,
        public array $payload,
    ) {
        if ($this->cohortIdentity === '' || ! in_array($this->sourceType, self::TYPES, true)) {
            throw new InvalidArgumentException('workforce_capacity_frozen_projection_invalid');
        }
        foreach (['rate', 'headcount', 'hours_per_day', 'planned_hours'] as $decimalField) {
            if (array_key_exists($decimalField, $this->payload)
                && $this->payload[$decimalField] !== null
                && ! is_string($this->payload[$decimalField])) {
                throw new InvalidArgumentException('workforce_capacity_frozen_projection_type_invalid');
            }
        }
        foreach (['valid_from', 'valid_to', 'work_date', 'start_date', 'end_date', 'dismissal_date'] as $dateField) {
            if (array_key_exists($dateField, $this->payload)
                && $this->payload[$dateField] !== null
            ) {
                $date = is_string($this->payload[$dateField])
                    ? DateTimeImmutable::createFromFormat('!Y-m-d', $this->payload[$dateField])
                    : false;
                if ($date === false || $date->format('Y-m-d') !== $this->payload[$dateField]) {
                    throw new InvalidArgumentException('workforce_capacity_frozen_projection_type_invalid');
                }
            }
        }
        if (array_key_exists('deleted_at', $this->payload)
            && $this->payload['deleted_at'] !== null
            && ! is_string($this->payload['deleted_at'])) {
            throw new InvalidArgumentException('workforce_capacity_frozen_projection_type_invalid');
        }
        if (array_key_exists('week_pattern', $this->payload)
            && $this->payload['week_pattern'] !== null
            && ! is_string($this->payload['week_pattern'])
            && ! is_array($this->payload['week_pattern'])) {
            throw new InvalidArgumentException('workforce_capacity_frozen_projection_type_invalid');
        }
    }
}
