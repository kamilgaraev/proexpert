<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BaselineScheduleTaskSource
{
    public function __construct(
        public int $taskId,
        public ?DateTimeImmutable $baselineStart,
        public ?DateTimeImmutable $baselineEnd,
        public DateTimeImmutable $plannedStart,
        public DateTimeImmutable $plannedEnd,
        public ?int $baselineDurationDays,
        public int $plannedDurationDays,
        public int $totalFloatDays,
        public int $freeFloatDays,
        public bool $critical,
        public string $status,
        public ?int $scheduleId = null,
        public ?string $wbsCode = null,
        public ?string $taskName = null,
    ) {
        if ($taskId < 1
            || ($scheduleId !== null && $scheduleId < 1)
            || trim($status) === ''
            || $plannedEnd < $plannedStart
            || (($baselineStart === null) !== ($baselineEnd === null))
        ) {
            throw new InvalidArgumentException('baseline_schedule_task_source_invalid');
        }
    }
}
