<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\Enums\Schedule\TaskStatusEnum;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class ScheduleRagSource implements RagSourceCollectorInterface
{
    public function sourceType(): string
    {
        return 'schedule';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        $query = ProjectSchedule::query()
            ->with('project')
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id');

        foreach ($query->cursor() as $schedule) {
            yield $this->chunk($schedule);
        }

        $taskQuery = ScheduleTask::query()
            ->with(['schedule.project', 'assignedUser', 'workType', 'measurementUnit'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->whereHas(
                'schedule',
                static fn ($scheduleQuery) => $scheduleQuery->where('project_id', $projectId)
            ))
            ->orderBy('id');

        foreach ($taskQuery->cursor() as $task) {
            yield $this->taskChunk($task);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if ($entityType === 'schedule') {
            $schedule = ProjectSchedule::query()
                ->with('project')
                ->where('organization_id', $organizationId)
                ->where('id', $entityId)
                ->first();

            return $schedule instanceof ProjectSchedule ? [$this->chunk($schedule)] : [];
        }

        if ($entityType === 'schedule_task') {
            $task = ScheduleTask::query()
                ->with(['schedule.project', 'assignedUser', 'workType', 'measurementUnit'])
                ->where('organization_id', $organizationId)
                ->where('id', $entityId)
                ->first();

            return $task instanceof ScheduleTask ? [$this->taskChunk($task)] : [];
        }

        return [];
    }

    private function taskChunk(ScheduleTask $task): RagChunkData
    {
        $projectId = $task->schedule?->project_id !== null ? (int) $task->schedule->project_id : null;
        $content = implode("\n", array_filter([
            'Задача графика: '.$this->stringValue($task->name),
            'График: '.$this->stringValue($task->schedule?->name),
            'Проект: '.$this->stringValue($task->schedule?->project?->name),
            'WBS: '.$this->stringValue($task->wbs_code),
            'Тип: '.$this->stringValue($task->task_type),
            'Статус: '.$this->stringValue($task->status),
            'Приоритет: '.$this->stringValue($task->priority),
            'Плановый период: '.$this->dateValue($task->planned_start_date).' - '.$this->dateValue($task->planned_end_date),
            'Базовый период: '.$this->dateValue($task->baseline_start_date).' - '.$this->dateValue($task->baseline_end_date),
            'Фактический период: '.$this->dateValue($task->actual_start_date).' - '.$this->dateValue($task->actual_end_date),
            'Прогресс: '.$this->numberValue($task->progress_percent).'%',
            'Объем: '.$this->numberValue($task->quantity).' '.$this->stringValue($task->measurementUnit?->short_name),
            'Выполнено: '.$this->numberValue($task->completed_quantity).' '.$this->stringValue($task->measurementUnit?->short_name),
            'Вид работ: '.$this->stringValue($task->workType?->name),
            'Ответственный: '.$this->stringValue($task->assignedUser?->name),
            'Критическая задача: '.($task->is_critical ? 'yes' : 'no'),
            'Ограничение: '.$this->stringValue($task->constraint_type).' '.$this->dateValue($task->constraint_date),
            'Описание: '.$this->stringValue($task->description),
            'Заметки: '.$this->stringValue($task->notes),
        ], static fn (string $line): bool => ! str_ends_with($line, ': ') && ! str_ends_with($line, ': -')));

        return new RagChunkData(
            organizationId: (int) $task->organization_id,
            projectId: $projectId,
            sourceType: $this->sourceType(),
            entityType: 'schedule_task',
            entityId: (int) $task->id,
            title: 'Задача графика: '.$this->stringValue($task->name),
            content: $content,
            metadata: [
                'status' => $this->scalarValue($task->status),
                'priority' => $this->scalarValue($task->priority),
                'project_id' => $projectId,
                'schedule_id' => $task->schedule_id,
                'parent_task_id' => $task->parent_task_id,
                'is_critical' => (bool) $task->is_critical,
                'progress_percent' => $task->progress_percent,
                'planned_end_date' => $this->dateValue($task->planned_end_date),
            ],
            updatedAt: $task->updated_at
        );
    }

    private function chunk(ProjectSchedule $schedule): RagChunkData
    {
        $activeStatuses = TaskStatusEnum::activeStatuses();
        $baseTaskQuery = ScheduleTask::query()
            ->where('organization_id', $schedule->organization_id)
            ->where('schedule_id', $schedule->id);

        $activeCount = (clone $baseTaskQuery)->whereIn('status', $activeStatuses)->count();
        $overdueTasks = (clone $baseTaskQuery)
            ->whereIn('status', $activeStatuses)
            ->whereDate('planned_end_date', '<', CarbonImmutable::today())
            ->orderBy('planned_end_date')
            ->limit(5)
            ->pluck('name')
            ->all();
        $nearestTasks = (clone $baseTaskQuery)
            ->whereIn('status', $activeStatuses)
            ->whereNotNull('planned_start_date')
            ->orderBy('planned_start_date')
            ->limit(5)
            ->get(['name', 'planned_start_date', 'planned_end_date', 'progress_percent'])
            ->map(fn (ScheduleTask $task): string => sprintf(
                '%s (%s - %s, %s%%)',
                $task->name,
                $this->dateValue($task->planned_start_date),
                $this->dateValue($task->planned_end_date),
                $this->numberValue($task->progress_percent)
            ))
            ->all();

        $content = implode("\n", array_filter([
            "График: {$schedule->name}",
            'Проект: '.$this->stringValue($schedule->project?->name),
            'Статус: '.$this->stringValue($schedule->status),
            'Плановый период: '.$this->dateValue($schedule->planned_start_date).' - '.$this->dateValue($schedule->planned_end_date),
            'Прогресс: '.$this->numberValue($schedule->overall_progress_percent).'%',
            "Активных задач: {$activeCount}",
            'Просроченные задачи: '.implode(', ', $overdueTasks),
            'Ближайшие задачи: '.implode(', ', $nearestTasks),
        ], static fn (string $line): bool => ! str_ends_with($line, ': ') && ! str_ends_with($line, ': -')));

        return new RagChunkData(
            organizationId: (int) $schedule->organization_id,
            projectId: $schedule->project_id !== null ? (int) $schedule->project_id : null,
            sourceType: $this->sourceType(),
            entityType: 'schedule',
            entityId: (int) $schedule->id,
            title: 'График: '.$schedule->name,
            content: $content,
            metadata: [
                'status' => $this->scalarValue($schedule->status),
                'active_tasks_count' => $activeCount,
                'overdue_tasks' => array_values($overdueTasks),
            ],
            updatedAt: $schedule->updated_at
        );
    }

    private function scalarValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    private function stringValue(mixed $value): string
    {
        $value = $this->scalarValue($value);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function numberValue(mixed $value): string
    {
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') : '0';
    }

    private function dateValue(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : '';
    }
}
