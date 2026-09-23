<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Services\Schedule\ScheduleTaskMutationService;
use App\Services\Schedule\ScheduleTaskService;
use DomainException;
use Illuminate\Support\Facades\DB;

final class MobileScheduleTaskService
{
    public function __construct(
        private readonly MobileProjectAccessResolver $projectAccess,
        private readonly ScheduleTaskService $tasks,
        private readonly ScheduleTaskMutationService $mutations,
        private readonly AuthorizationService $authorization,
    ) {}

    public function create(User $user, int $scheduleId, array $data): ScheduleTask
    {
        $schedule = $this->accessibleSchedule($user, $scheduleId);
        $parentId = isset($data['parent_task_id']) ? (int) $data['parent_task_id'] : null;
        $parent = $parentId === null ? null : ScheduleTask::query()
            ->where('schedule_id', $schedule->id)
            ->where('organization_id', $schedule->organization_id)
            ->find($parentId);

        if ($parentId !== null && $parent === null) {
            throw new DomainException(trans_message('schedule_management.task_not_found'));
        }

        return DB::transaction(function () use ($user, $schedule, $data, $parent): ScheduleTask {
            $afterId = isset($data['insert_after_id']) ? (int) $data['insert_after_id'] : null;
            $sortOrder = $afterId !== null
                ? $this->tasks->insertTaskAfter($schedule->id, $afterId, $parent?->id)
                : (int) ($data['sort_order'] ?? $this->tasks->getNextSortOrder($schedule->id, $parent?->id));
            unset($data['insert_after_id']);

            $task = ScheduleTask::query()->create(array_merge($data, [
                'schedule_id' => $schedule->id,
                'organization_id' => $schedule->organization_id,
                'created_by_user_id' => $user->id,
                'parent_task_id' => $parent?->id,
                'level' => $parent ? ((int) $parent->level + 1) : 0,
                'sort_order' => $sortOrder,
            ]));

            if (array_key_exists('intervals', $data)) {
                $this->tasks->syncTaskIntervals($task, $data['intervals']);
            }

            return $task->fresh(['assignedUser', 'workType', 'parentTask', 'intervals']) ?? $task;
        });
    }

    public function update(User $user, int $scheduleId, int $taskId, array $data): array
    {
        $schedule = $this->accessibleSchedule($user, $scheduleId);
        $task = ScheduleTask::query()
            ->where('schedule_id', $schedule->id)
            ->where('organization_id', $schedule->organization_id)
            ->find($taskId);

        if (! $task) {
            throw new DomainException(trans_message('schedule_management.task_not_found'));
        }

        return $this->mutations->updateTask($schedule, $task, $data);
    }

    public function updateById(User $user, int $taskId, array $data): array
    {
        $organizationId = (int) $user->current_organization_id;
        $task = ScheduleTask::query()
            ->where('organization_id', $organizationId)
            ->find($taskId);

        if (! $task) {
            throw new DomainException(trans_message('schedule_management.task_not_found'));
        }

        return $this->update($user, (int) $task->schedule_id, $taskId, $data);
    }

    public function show(User $user, int $taskId): array
    {
        $organizationId = (int) $user->current_organization_id;
        $task = ScheduleTask::query()
            ->where('organization_id', $organizationId)
            ->with(['measurementUnit:id,name,short_name'])
            ->withCount('childTasks')
            ->find($taskId);

        if (! $task) {
            throw new DomainException(trans_message('schedule_management.task_not_found'));
        }

        $schedule = $this->accessibleSchedule($user, (int) $task->schedule_id, 'schedule.view');
        $status = is_object($task->status) && isset($task->status->value)
            ? (string) $task->status->value
            : (string) $task->status;
        $taskType = is_object($task->task_type) && isset($task->task_type->value)
            ? (string) $task->task_type->value
            : (string) $task->task_type;

        return [
            'id' => (int) $task->id,
            'schedule_id' => (int) $schedule->id,
            'parent_task_id' => $task->parent_task_id,
            'name' => $task->name,
            'description' => $task->description,
            'task_type' => $taskType,
            'task_type_label' => method_exists($task->task_type, 'label')
                ? $task->task_type->label()
                : $taskType,
            'status' => $status,
            'status_label' => method_exists($task->status, 'label')
                ? $task->status->label()
                : $status,
            'status_color' => method_exists($task->status, 'color')
                ? $task->status->color()
                : '#6B7280',
            'progress_percent' => round((float) ($task->progress_percent ?? 0), 1),
            'is_critical' => (bool) $task->is_critical,
            'level' => (int) ($task->level ?? 0),
            'children_count' => (int) ($task->child_tasks_count ?? 0),
            'planned_start_date' => $task->planned_start_date?->format('Y-m-d'),
            'planned_end_date' => $task->planned_end_date?->format('Y-m-d'),
            'actual_start_date' => $task->actual_start_date?->format('Y-m-d'),
            'actual_end_date' => $task->actual_end_date?->format('Y-m-d'),
            'quantity' => $task->quantity !== null ? (float) $task->quantity : null,
            'measurement_unit' => $task->measurementUnit?->short_name ?? $task->measurementUnit?->name,
        ];
    }

    private function accessibleSchedule(User $user, int $scheduleId, string $permission = 'schedule.edit'): ProjectSchedule
    {
        $organizationId = (int) $user->current_organization_id;
        if ($organizationId <= 0 || ! $this->authorization->can($user, $permission, [
            'organization_id' => $organizationId,
            'context_type' => 'organization',
        ])) {
            throw new DomainException(trans_message('mobile_schedule.errors.project_not_found'));
        }

        $schedule = ProjectSchedule::query()
            ->where('organization_id', $organizationId)
            ->find($scheduleId);

        if (! $schedule) {
            throw new DomainException(trans_message('schedule_management.schedule_not_found'));
        }

        $this->projectAccess->assert(
            $user,
            $organizationId,
            (int) $schedule->project_id,
            trans_message('mobile_schedule.errors.project_not_found'),
        );

        return $schedule;
    }
}
