<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\ReadOnly;

use App\Models\Organization;
use App\Models\User;

class GetScheduleSnapshotTool extends AbstractReadOnlyTool
{
    public function getName(): string
    {
        return 'get_schedule_snapshot';
    }

    public function getDescription(): string
    {
        return 'Возвращает read-only сводку по графикам: прогресс, критические и просроченные задачи, ближайшие этапы.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => ['type' => 'integer', 'description' => 'ID проекта'],
                'schedule_id' => ['type' => 'integer', 'description' => 'ID графика'],
                'status' => ['type' => 'string', 'description' => 'Статус задачи'],
                'critical_only' => ['type' => 'boolean', 'description' => 'Показывать только критические задачи'],
                'date_from' => ['type' => 'string', 'description' => 'Дата начала периода YYYY-MM-DD'],
                'date_to' => ['type' => 'string', 'description' => 'Дата конца периода YYYY-MM-DD'],
                'limit' => ['type' => 'integer', 'description' => 'Максимум задач, от 1 до 30', 'default' => 10],
            ],
        ];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        unset($user);

        if (!$this->hasTable('project_schedules')) {
            return $this->tableUnavailable('schedules', 'project_schedules');
        }

        $scheduleIds = $this->scheduleIds($arguments, $organization);
        $tasks = $this->tasks($arguments, $organization, $scheduleIds);

        return [
            'status' => 'success',
            'domain' => 'schedules',
            'summary' => [
                'schedules_count' => count($scheduleIds),
                'tasks_count' => count($tasks),
                'critical_tasks_count' => count(array_filter($tasks, static fn (array $task): bool => (bool) $task['is_critical'])),
                'overdue_tasks_count' => count(array_filter($tasks, static fn (array $task): bool => (bool) $task['is_overdue'])),
            ],
            'tasks' => $tasks,
        ];
    }

    private function scheduleIds(array $arguments, Organization $organization): array
    {
        $query = $this->withoutDeleted($this->orgTable('project_schedules', $organization), 'project_schedules');
        $projectId = $this->intArg($arguments, 'project_id');
        $scheduleId = $this->intArg($arguments, 'schedule_id');

        if ($projectId !== null) {
            $query->where('project_schedules.project_id', $projectId);
        }

        if ($scheduleId !== null) {
            $query->where('project_schedules.id', $scheduleId);
        }

        return array_map('intval', $query->limit(self::MAX_LIMIT)->pluck('project_schedules.id')->all());
    }

    private function tasks(array $arguments, Organization $organization, array $scheduleIds): array
    {
        if ($scheduleIds === [] || !$this->hasTable('schedule_tasks')) {
            return [];
        }

        $query = $this->withoutDeleted($this->orgTable('schedule_tasks', $organization), 'schedule_tasks')
            ->whereIn('schedule_tasks.schedule_id', $scheduleIds);

        $status = $this->stringArg($arguments, 'status');
        if ($status !== null) {
            $query->where('schedule_tasks.status', $status);
        }

        if ((bool) ($arguments['critical_only'] ?? false)) {
            $query->where('schedule_tasks.is_critical', true);
        }

        $this->applyDateRange($query, 'schedule_tasks.planned_start_date', $arguments);

        return $query
            ->select([
                'schedule_tasks.id',
                'schedule_tasks.schedule_id',
                'schedule_tasks.name',
                'schedule_tasks.status',
                'schedule_tasks.priority',
                'schedule_tasks.planned_start_date',
                'schedule_tasks.planned_end_date',
                'schedule_tasks.progress_percent',
                'schedule_tasks.is_critical',
                'schedule_tasks.completed_quantity',
                'schedule_tasks.quantity',
            ])
            ->orderBy('schedule_tasks.planned_end_date')
            ->limit($this->limit($arguments))
            ->get()
            ->map(fn (object $task): array => [
                'id' => (int) $task->id,
                'schedule_id' => $task->schedule_id,
                'name' => $task->name,
                'status' => $task->status,
                'priority' => $task->priority,
                'planned_start_date' => $task->planned_start_date,
                'planned_end_date' => $task->planned_end_date,
                'progress_percent' => $task->progress_percent !== null ? (float) $task->progress_percent : null,
                'is_critical' => (bool) $task->is_critical,
                'is_overdue' => $task->planned_end_date !== null
                    && $task->planned_end_date < now()->toDateString()
                    && (float) ($task->progress_percent ?? 0) < 100,
                'quantity' => $task->quantity !== null ? (float) $task->quantity : null,
                'completed_quantity' => $task->completed_quantity !== null ? (float) $task->completed_quantity : null,
            ])
            ->all();
    }
}
