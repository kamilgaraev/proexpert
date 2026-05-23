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
use Throwable;

final class ScheduleRagSource implements RagSourceCollectorInterface
{
    public function sourceType(): string
    {
        return 'schedule';
    }

    public function enabled(): bool
    {
        try {
            return (bool) config('ai-assistant.rag.enabled', false);
        } catch (Throwable) {
            return false;
        }
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
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if ($entityType !== 'schedule') {
            return [];
        }

        $schedule = ProjectSchedule::query()
            ->with('project')
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $schedule instanceof ProjectSchedule ? [$this->chunk($schedule)] : [];
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
