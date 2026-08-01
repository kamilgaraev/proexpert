<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ScheduleRevisionDraft
{
    private function __construct(
        public int $organizationId,
        public int $projectId,
        public int $scheduleId,
        public string $planningTimezone,
        public array $calendar,
        public string $sourceWatermark,
        public array $tasks,
        public array $dependencies,
    ) {}

    public static function fromArray(array $data): self
    {
        foreach (['organization_id', 'project_id', 'schedule_id'] as $key) {
            if (! is_int($data[$key] ?? null) || $data[$key] <= 0) {
                throw new InvalidArgumentException('lookahead_readiness_schedule_lineage_invalid');
            }
        }
        $timezone = $data['planning_timezone'] ?? null;
        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('lookahead_readiness_timezone_invalid');
        }
        if (($data['expected_source_watermark'] ?? null) !== ($data['observed_source_watermark'] ?? null)
            || ! is_string($data['observed_source_watermark'] ?? null)
            || $data['observed_source_watermark'] === '') {
            throw new InvalidArgumentException('lookahead_readiness_stale_schedule_source');
        }
        $calendar = $data['calendar'] ?? null;
        if (! is_array($calendar)
            || ! is_string($calendar['calendar_id'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $calendar['calendar_hash'] ?? '') !== 1
            || ! is_array($calendar['working_weekdays'] ?? null)) {
            throw new InvalidArgumentException('lookahead_readiness_calendar_invalid');
        }
        $tasks = $data['tasks'] ?? null;
        if (! is_array($tasks) || $tasks === []) {
            throw new InvalidArgumentException('lookahead_readiness_schedule_tasks_missing');
        }
        $taskIds = [];
        foreach ($tasks as $task) {
            if (! self::validTask($task) || isset($taskIds[$task['external_id']])) {
                throw new InvalidArgumentException('lookahead_readiness_schedule_task_invalid');
            }
            $taskIds[$task['external_id']] = true;
        }
        foreach ($tasks as $task) {
            if ($task['parent_external_id'] !== null && ! isset($taskIds[$task['parent_external_id']])) {
                throw new InvalidArgumentException('lookahead_readiness_schedule_task_parent_invalid');
            }
        }
        $dependencies = $data['dependencies'] ?? null;
        if (! is_array($dependencies)) {
            throw new InvalidArgumentException('lookahead_readiness_dependency_lineage_invalid');
        }
        foreach ($dependencies as $dependency) {
            if (! is_array($dependency)
                || ! isset($taskIds[$dependency['predecessor_external_id'] ?? ''])
                || ! isset($taskIds[$dependency['successor_external_id'] ?? ''])
                || ! in_array($dependency['type'] ?? null, [
                    'finish_to_start',
                    'start_to_start',
                    'finish_to_finish',
                    'start_to_finish',
                ], true)
                || ! is_int($dependency['lag_minutes'] ?? null)) {
                throw new InvalidArgumentException('lookahead_readiness_dependency_lineage_invalid');
            }
        }

        return new self(
            $data['organization_id'],
            $data['project_id'],
            $data['schedule_id'],
            $timezone,
            $calendar,
            $data['observed_source_watermark'],
            $tasks,
            $dependencies,
        );
    }

    private static function validTask(mixed $task): bool
    {
        if (! is_array($task)
            || ! is_string($task['external_id'] ?? null)
            || $task['external_id'] === ''
            || ! is_int($task['source_task_id'] ?? null)
            || $task['source_task_id'] <= 0
            || ! is_string($task['wbs_code'] ?? null)
            || ! is_string($task['name'] ?? null)
            || ! is_string($task['task_class'] ?? null)
            || $task['task_class'] === ''
            || ! is_int($task['duration_minutes'] ?? null)
            || $task['duration_minutes'] < 0
            || ! is_bool($task['critical'] ?? null)) {
            return false;
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $task['planned_start'] ?? '');
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $task['planned_end'] ?? '');

        return $start instanceof DateTimeImmutable && $end instanceof DateTimeImmutable && $start <= $end;
    }
}
