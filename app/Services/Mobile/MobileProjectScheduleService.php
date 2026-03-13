<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Enums\Schedule\ScheduleStatusEnum;
use App\Enums\Schedule\TaskStatusEnum;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;

class MobileProjectScheduleService
{
    public function list(User $user, ?int $projectId): array
    {
        $organizationId = (int) $user->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(trans_message('mobile_schedule.errors.no_organization'));
        }

        $project = $this->resolveAccessibleProject($user, $organizationId, $projectId);

        $schedules = ProjectSchedule::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $project->id)
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn($query) => $query->where('status', TaskStatusEnum::COMPLETED->value),
                'tasks as overdue_tasks_count' => fn($query) => $query
                    ->whereDate('planned_end_date', '<', now()->toDateString())
                    ->where('status', '!=', TaskStatusEnum::COMPLETED->value),
            ])
            ->orderByDesc('created_at')
            ->get();

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'summary' => [
                'total_schedules' => $schedules->count(),
                'active_schedules' => $schedules
                    ->filter(fn(ProjectSchedule $schedule): bool => $this->resolveStatusValue($schedule->status) === ScheduleStatusEnum::ACTIVE->value)
                    ->count(),
                'completed_schedules' => $schedules
                    ->filter(fn(ProjectSchedule $schedule): bool => $this->resolveStatusValue($schedule->status) === ScheduleStatusEnum::COMPLETED->value)
                    ->count(),
                'average_progress_percent' => round((float) ($schedules->avg('overall_progress_percent') ?? 0), 1),
            ],
            'schedules' => $this->mapSchedules($schedules),
        ];
    }

    public function show(User $user, int $scheduleId): array
    {
        $organizationId = (int) $user->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(trans_message('mobile_schedule.errors.no_organization'));
        }

        $schedule = ProjectSchedule::query()
            ->where('organization_id', $organizationId)
            ->where('id', $scheduleId)
            ->with([
                'project:id,name',
                'tasks' => fn($query) => $query
                    ->with(['measurementUnit:id,name,short_name'])
                    ->withCount('childTasks')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ])
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn($query) => $query->where('status', TaskStatusEnum::COMPLETED->value),
                'tasks as in_progress_tasks_count' => fn($query) => $query->where('status', TaskStatusEnum::IN_PROGRESS->value),
                'tasks as overdue_tasks_count' => fn($query) => $query
                    ->whereDate('planned_end_date', '<', now()->toDateString())
                    ->where('status', '!=', TaskStatusEnum::COMPLETED->value),
            ])
            ->first();

        if (!$schedule) {
            throw new DomainException(trans_message('mobile_schedule.errors.load_failed'));
        }

        $this->assertProjectAccess($user, $organizationId, (int) $schedule->project_id);

        return [
            'project' => [
                'id' => $schedule->project?->id,
                'name' => $schedule->project?->name,
            ],
            'schedule' => $this->mapSchedule($schedule),
            'summary' => [
                'tasks_count' => (int) ($schedule->tasks_count ?? 0),
                'completed_tasks_count' => (int) ($schedule->completed_tasks_count ?? 0),
                'in_progress_tasks_count' => (int) ($schedule->in_progress_tasks_count ?? 0),
                'overdue_tasks_count' => (int) ($schedule->overdue_tasks_count ?? 0),
            ],
            'tasks' => $this->mapTasks($schedule->tasks),
        ];
    }

    private function resolveAccessibleProject(User $user, int $organizationId, ?int $projectId): Project
    {
        if (($projectId ?? 0) <= 0) {
            throw new DomainException(trans_message('mobile_schedule.errors.project_not_found'));
        }

        return $this->findAccessibleProject($user, $organizationId, $projectId)
            ?? throw new DomainException(trans_message('mobile_schedule.errors.project_not_found'));
    }

    private function assertProjectAccess(User $user, int $organizationId, int $projectId): void
    {
        if (!$this->findAccessibleProject($user, $organizationId, $projectId)) {
            throw new DomainException(trans_message('mobile_schedule.errors.project_not_found'));
        }
    }

    private function findAccessibleProject(User $user, int $organizationId, int $projectId): ?Project
    {
        $query = Project::query()
            ->where('organization_id', $organizationId)
            ->where('id', $projectId);

        if (!$user->isOrganizationAdmin($organizationId)) {
            $query->whereHas('users', function ($usersQuery) use ($user): void {
                $usersQuery->where('users.id', $user->id);
            });
        }

        return $query->first();
    }

    private function mapSchedules(Collection $schedules): array
    {
        return $schedules
            ->map(fn(ProjectSchedule $schedule): array => $this->mapSchedule($schedule))
            ->values()
            ->all();
    }

    private function mapSchedule(ProjectSchedule $schedule): array
    {
        $status = $this->resolveStatusValue($schedule->status);
        $progress = round((float) ($schedule->overall_progress_percent ?? 0), 1);

        return [
            'id' => $schedule->id,
            'project_id' => $schedule->project_id,
            'name' => $schedule->name,
            'description' => $schedule->description,
            'status' => $status,
            'status_label' => $this->resolveStatusLabel($schedule->status),
            'status_color' => $this->resolveStatusColor($status),
            'overall_progress_percent' => $progress,
            'progress_color' => $this->resolveProgressColor($progress),
            'health_status' => $schedule->health_status,
            'planned_start_date' => $schedule->planned_start_date?->format('Y-m-d'),
            'planned_end_date' => $schedule->planned_end_date?->format('Y-m-d'),
            'planned_duration_days' => $schedule->planned_duration_days,
            'actual_start_date' => $schedule->actual_start_date?->format('Y-m-d'),
            'actual_end_date' => $schedule->actual_end_date?->format('Y-m-d'),
            'critical_path_calculated' => (bool) $schedule->critical_path_calculated,
            'critical_path_duration_days' => $schedule->critical_path_duration_days,
            'tasks_count' => (int) ($schedule->tasks_count ?? 0),
            'completed_tasks_count' => (int) ($schedule->completed_tasks_count ?? 0),
            'overdue_tasks_count' => (int) ($schedule->overdue_tasks_count ?? 0),
            'created_at' => $schedule->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $schedule->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function mapTasks(Collection $tasks): array
    {
        return $tasks
            ->map(function ($task): array {
                $status = $this->resolveStatusValue($task->status);

                return [
                    'id' => $task->id,
                    'parent_task_id' => $task->parent_task_id,
                    'name' => $task->name,
                    'description' => $task->description,
                    'task_type' => $task->task_type?->value ?? $task->task_type,
                    'task_type_label' => method_exists($task->task_type, 'label')
                        ? $task->task_type->label()
                        : (string) ($task->task_type?->value ?? $task->task_type ?? ''),
                    'status' => $status,
                    'status_label' => $this->resolveStatusLabel($task->status),
                    'status_color' => method_exists($task->status, 'color')
                        ? $task->status->color()
                        : $this->resolveStatusColor($status),
                    'progress_percent' => round((float) ($task->progress_percent ?? 0), 1),
                    'is_critical' => (bool) $task->is_critical,
                    'level' => (int) ($task->level ?? 0),
                    'children_count' => (int) ($task->child_tasks_count ?? 0),
                    'planned_start_date' => $task->planned_start_date?->format('Y-m-d'),
                    'planned_end_date' => $task->planned_end_date?->format('Y-m-d'),
                    'planned_duration_days' => $task->planned_duration_days,
                    'actual_start_date' => $task->actual_start_date?->format('Y-m-d'),
                    'actual_end_date' => $task->actual_end_date?->format('Y-m-d'),
                    'quantity' => $task->quantity !== null ? (float) $task->quantity : null,
                    'completed_quantity' => $task->completed_quantity !== null ? (float) $task->completed_quantity : null,
                    'measurement_unit' => $task->measurementUnit?->short_name ?? $task->measurementUnit?->name,
                ];
            })
            ->values()
            ->all();
    }

    private function resolveStatusValue(mixed $status): string
    {
        return is_object($status) && isset($status->value)
            ? (string) $status->value
            : (string) $status;
    }

    private function resolveStatusLabel(mixed $status): string
    {
        if (is_object($status) && method_exists($status, 'label')) {
            return $status->label();
        }

        return $this->resolveStatusValue($status);
    }

    private function resolveStatusColor(string $status): string
    {
        return match ($status) {
            ScheduleStatusEnum::DRAFT->value,
            TaskStatusEnum::NOT_STARTED->value => '#6B7280',
            ScheduleStatusEnum::ACTIVE->value,
            TaskStatusEnum::IN_PROGRESS->value => '#3B82F6',
            ScheduleStatusEnum::PAUSED->value,
            TaskStatusEnum::ON_HOLD->value => '#F59E0B',
            ScheduleStatusEnum::COMPLETED->value,
            TaskStatusEnum::COMPLETED->value => '#10B981',
            ScheduleStatusEnum::CANCELLED->value,
            TaskStatusEnum::CANCELLED->value => '#EF4444',
            TaskStatusEnum::WAITING->value => '#8B5CF6',
            default => '#6B7280',
        };
    }

    private function resolveProgressColor(float $progress): string
    {
        return match (true) {
            $progress < 25 => '#EF4444',
            $progress < 50 => '#F59E0B',
            $progress < 75 => '#3B82F6',
            $progress < 100 => '#10B981',
            default => '#059669',
        };
    }
}
