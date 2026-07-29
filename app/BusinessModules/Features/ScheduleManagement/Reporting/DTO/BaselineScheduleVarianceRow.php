<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\DTO;

use DateTimeImmutable;

final readonly class BaselineScheduleVarianceRow
{
    public function __construct(
        public int $taskId,
        public ?int $startVarianceDays,
        public ?int $endVarianceDays,
        public ?int $durationVarianceDays,
        public int $totalFloatDays,
        public int $freeFloatDays,
        public bool $critical,
        public bool $overdue,
        public int $overdueDays,
        public array $warningCodes,
        public string $status,
        public ?int $scheduleId,
        public ?string $wbsCode,
        public ?string $taskName,
    ) {
    }

    public static function fromSource(
        BaselineScheduleTaskSource $source,
        ?DateTimeImmutable $asOf = null,
    ): self {
        $asOf ??= new DateTimeImmutable('today');
        $hasBaseline = $source->baselineStart !== null
            && $source->baselineEnd !== null
            && $source->baselineDurationDays !== null;
        $completed = in_array($source->status, ['completed', 'cancelled'], true);
        $overdueDays = $completed || $source->plannedEnd >= $asOf
            ? 0
            : (int) $source->plannedEnd->diff($asOf)->days;

        return new self(
            taskId: $source->taskId,
            startVarianceDays: $hasBaseline
                ? self::signedDays($source->baselineStart, $source->plannedStart)
                : null,
            endVarianceDays: $hasBaseline
                ? self::signedDays($source->baselineEnd, $source->plannedEnd)
                : null,
            durationVarianceDays: $hasBaseline
                ? $source->plannedDurationDays - $source->baselineDurationDays
                : null,
            totalFloatDays: $source->totalFloatDays,
            freeFloatDays: $source->freeFloatDays,
            critical: $source->critical,
            overdue: $overdueDays > 0,
            overdueDays: $overdueDays,
            warningCodes: $hasBaseline ? [] : ['SCHEDULE_BASELINE_MISSING'],
            status: $source->status,
            scheduleId: $source->scheduleId,
            wbsCode: $source->wbsCode,
            taskName: $source->taskName,
        );
    }

    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'schedule_id' => $this->scheduleId,
            'wbs_code' => $this->wbsCode,
            'task_name' => $this->taskName,
            'start_variance_days' => $this->startVarianceDays,
            'end_variance_days' => $this->endVarianceDays,
            'duration_variance_days' => $this->durationVarianceDays,
            'total_float_days' => $this->totalFloatDays,
            'free_float_days' => $this->freeFloatDays,
            'critical' => $this->critical,
            'overdue' => $this->overdue,
            'overdue_days' => $this->overdueDays,
            'warning_codes' => $this->warningCodes,
            'status' => $this->status,
        ];
    }

    private static function signedDays(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $interval = $from->diff($to);
        $days = (int) $interval->days;

        return $interval->invert === 1 ? -$days : $days;
    }
}
