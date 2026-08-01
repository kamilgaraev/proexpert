<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO;

use InvalidArgumentException;

final readonly class WorkforceCapacityCaptureCommand
{
    private const SOURCE_TYPES = [
        'staff_unit',
        'assignment',
        'employee_lifecycle',
        'schedule',
        'schedule_day',
        'absence',
        'business_trip',
        'capture_request',
    ];

    public function __construct(
        public string $mutationId,
        public int $organizationId,
        public string $sourceType,
        public ?array $oldState,
        public ?array $newState,
        public string $captureKind,
        public ?int $actorUserId,
        public ?string $serviceActor,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9:_\-.]{7,190}$/D', $this->mutationId) !== 1
            || $this->organizationId < 1
            || ! in_array($this->sourceType, self::SOURCE_TYPES, true)
            || ($this->oldState === null && $this->newState === null)
            || ! in_array($this->captureKind, ['change_capture', 'scheduled_close', 'manual_recompute'], true)
            || ($this->captureKind === 'manual_recompute' && ($this->actorUserId === null || $this->actorUserId < 1))
            || ($this->captureKind !== 'manual_recompute' && ($this->serviceActor === null || trim($this->serviceActor) === ''))) {
            throw new InvalidArgumentException('workforce_capacity_capture_command_invalid');
        }
    }
}
