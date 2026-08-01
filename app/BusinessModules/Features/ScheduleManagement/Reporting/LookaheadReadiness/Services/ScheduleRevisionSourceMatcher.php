<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ScheduleRevisionDraft;
use BackedEnum;
use DateTimeInterface;
use LogicException;

final class ScheduleRevisionSourceMatcher
{
    public function assertMatches(
        ScheduleRevisionDraft $draft,
        array $schedule,
        array $sourceTasks,
        array $sourceDependencies,
    ): void {
        if ((int) ($schedule['id'] ?? 0) !== $draft->scheduleId
            || (int) ($schedule['organization_id'] ?? 0) !== $draft->organizationId
            || (int) ($schedule['project_id'] ?? 0) !== $draft->projectId
            || ($schedule['timezone'] ?? null) !== $draft->planningTimezone) {
            throw new LogicException('lookahead_readiness_schedule_snapshot_mismatch');
        }

        $draftTasksBySource = [];
        foreach ($draft->tasks as $task) {
            $sourceTaskId = $task['source_task_id'];
            if (isset($draftTasksBySource[$sourceTaskId])) {
                throw new LogicException('lookahead_readiness_schedule_snapshot_mismatch');
            }
            $draftTasksBySource[$sourceTaskId] = $task;
        }
        if (count($draftTasksBySource) !== count($sourceTasks)) {
            throw new LogicException('lookahead_readiness_schedule_snapshot_mismatch');
        }

        $externalIdBySourceId = [];
        foreach ($draftTasksBySource as $sourceTaskId => $task) {
            $externalIdBySourceId[(int) $sourceTaskId] = $task['external_id'];
        }
        foreach ($sourceTasks as $sourceTask) {
            $sourceTaskId = (int) ($sourceTask['id'] ?? 0);
            $task = $draftTasksBySource[$sourceTaskId] ?? null;
            $parentTaskId = $sourceTask['parent_task_id'] ?? null;
            $expectedParent = $parentTaskId === null ? null : ($externalIdBySourceId[(int) $parentTaskId] ?? false);
            if (! is_array($task)
                || $expectedParent === false
                || $task['wbs_code'] !== (string) ($sourceTask['wbs_code'] ?? '')
                || $task['name'] !== (string) ($sourceTask['name'] ?? '')
                || $task['planned_start'] !== $this->date($sourceTask['planned_start_date'] ?? null)
                || $task['planned_end'] !== $this->date($sourceTask['planned_end_date'] ?? null)
                || $this->decimal($task['planned_quantity'] ?? null) !== $this->decimal($sourceTask['quantity'] ?? null)
                || $this->decimal($task['planned_work_hours'] ?? null) !== $this->decimal($sourceTask['planned_work_hours'] ?? null)
                || $task['critical'] !== (bool) ($sourceTask['is_critical'] ?? false)
                || $task['parent_external_id'] !== $expectedParent) {
                throw new LogicException('lookahead_readiness_schedule_snapshot_mismatch');
            }
        }

        $expectedDependencies = [];
        foreach ($sourceDependencies as $dependency) {
            if (($dependency['is_active'] ?? true) !== true && (int) ($dependency['is_active'] ?? 0) !== 1) {
                continue;
            }
            $predecessor = $externalIdBySourceId[(int) ($dependency['predecessor_task_id'] ?? 0)] ?? null;
            $successor = $externalIdBySourceId[(int) ($dependency['successor_task_id'] ?? 0)] ?? null;
            $type = $dependency['dependency_type'] ?? null;
            if ($type instanceof BackedEnum) {
                $type = $type->value;
            }
            if (! is_string($predecessor) || ! is_string($successor) || ! is_string($type)) {
                throw new LogicException('lookahead_readiness_schedule_snapshot_mismatch');
            }
            $expectedDependencies[] = [
                'predecessor_external_id' => $predecessor,
                'successor_external_id' => $successor,
                'type' => $type,
                'lag_minutes' => $this->lagMinutes($dependency),
            ];
        }

        $actualDependencies = array_map(
            static fn (array $dependency): array => [
                'predecessor_external_id' => $dependency['predecessor_external_id'],
                'successor_external_id' => $dependency['successor_external_id'],
                'type' => $dependency['type'],
                'lag_minutes' => $dependency['lag_minutes'],
            ],
            $draft->dependencies,
        );
        $sort = static fn (array $left, array $right): int => $left <=> $right;
        usort($expectedDependencies, $sort);
        usort($actualDependencies, $sort);
        if ($expectedDependencies !== $actualDependencies) {
            throw new LogicException('lookahead_readiness_schedule_snapshot_mismatch');
        }
    }

    private function date(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    private function decimal(mixed $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 4, '.', '');
    }

    private function lagMinutes(array $dependency): int
    {
        $lagType = $dependency['lag_type'] ?? 'days';
        if (! in_array($lagType, ['days', 'hours'], true)) {
            throw new LogicException('lookahead_readiness_schedule_snapshot_mismatch');
        }

        return (int) round(
            ((float) ($dependency['lag_days'] ?? 0) * 1440)
            + ((float) ($dependency['lag_hours'] ?? 0) * 60),
        );
    }
}
