<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class CommitmentDraft
{
    private function __construct(
        public int $organizationId,
        public int $projectId,
        public int $scheduleId,
        public string $windowStart,
        public string $windowEnd,
        public string $planningTimezone,
        public array $tasks,
    ) {}

    public static function fromArray(array $data): self
    {
        foreach (['organization_id', 'project_id', 'schedule_id'] as $key) {
            if (! is_int($data[$key] ?? null) || $data[$key] <= 0) {
                throw new InvalidArgumentException('lookahead_readiness_commitment_lineage_invalid');
            }
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $data['window_start'] ?? '');
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $data['window_end'] ?? '');
        if (! $start instanceof DateTimeImmutable || ! $end instanceof DateTimeImmutable || $start > $end) {
            throw new InvalidArgumentException('lookahead_readiness_commitment_window_invalid');
        }
        $timezone = $data['planning_timezone'] ?? null;
        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('lookahead_readiness_timezone_invalid');
        }
        if (! is_array($data['tasks'] ?? null) || $data['tasks'] === []) {
            throw new InvalidArgumentException('lookahead_readiness_commitment_tasks_missing');
        }

        return new self(
            $data['organization_id'],
            $data['project_id'],
            $data['schedule_id'],
            $data['window_start'],
            $data['window_end'],
            $timezone,
            $data['tasks'],
        );
    }
}
