<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class HistoricalScheduleTaskState
{
    public function __construct(
        public int $taskId,
        public int $projectId,
        public int $scheduleId,
        public DateTimeImmutable $effectiveAt,
        public DateTimeImmutable $plannedStart,
        public DateTimeImmutable $plannedEnd,
        public int $plannedDurationDays,
        public string $status,
        public string $taskType,
        public ?string $wbsCode,
        public ?int $ownerId,
        public ?int $contractorId,
        public string $sourceHash,
        public string $taskName = '',
        public int $totalFloatDays = 0,
        public int $freeFloatDays = 0,
        public bool $critical = false,
        public bool $active = true,
        public ?int $zoneId = null,
        public int $version = 1,
    ) {
        if (min($taskId, $projectId, $scheduleId, $plannedDurationDays) < 1
            || $version < 1
            || $plannedEnd < $plannedStart
            || trim($status) === ''
            || trim($taskType) === ''
            || ($taskName !== '' && trim($taskName) === '')
            || ($ownerId !== null && $ownerId < 1)
            || ($contractorId !== null && $contractorId < 1)
            || ($zoneId !== null && $zoneId < 1)
            || preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1
        ) {
            throw new InvalidArgumentException('historical_schedule_task_state_invalid');
        }
    }
}
