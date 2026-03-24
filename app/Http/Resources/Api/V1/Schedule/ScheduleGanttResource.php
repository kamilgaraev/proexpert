<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Schedule;

use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\TaskDependency;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin ProjectSchedule
 */
class ScheduleGanttResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProjectSchedule $schedule */
        $schedule = $this->resource;

        $overallProgressPercent = $schedule->calculateOverallProgressPercent();
        $allTasks = $schedule->relationLoaded('tasks')
            ? $schedule->tasks
            : $schedule->tasks()->orderBy('sort_order')->get();
        $rootTasks = $this->buildTaskTree($allTasks);
        $hasBaseline = $allTasks->contains(
            fn (ScheduleTask $task) => $task->baseline_start_date !== null && $task->baseline_end_date !== null
        );
        $displaySettings = array_merge([
            'show_critical_path' => true,
            'show_float' => false,
            'show_baseline' => $hasBaseline,
            'show_actual_progress' => true,
        ], $schedule->display_settings ?? []);

        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'description' => $schedule->description,
            'planned_start_date' => $schedule->planned_start_date?->format('Y-m-d'),
            'planned_end_date' => $schedule->planned_end_date?->format('Y-m-d'),
            'actual_start_date' => $schedule->actual_start_date?->format('Y-m-d'),
            'actual_end_date' => $schedule->actual_end_date?->format('Y-m-d'),
            'overall_progress_percent' => $overallProgressPercent,
            'status' => $schedule->status->value ?? $schedule->status,
            'status_label' => method_exists($schedule->status, 'label')
                ? $schedule->status->label()
                : ($schedule->status->value ?? $schedule->status),
            'critical_path_calculated' => (bool) $schedule->critical_path_calculated,
            'critical_path_duration_days' => $schedule->critical_path_duration_days,
            'tasks' => ScheduleTaskGanttResource::collection($rootTasks),
            'dependencies' => $this->when(
                $schedule->relationLoaded('dependencies'),
                $schedule->dependencies->map(function (TaskDependency $dependency) use ($allTasks) {
                    return [
                        'id' => $dependency->id,
                        'predecessor_task_id' => $dependency->predecessor_task_id,
                        'successor_task_id' => $dependency->successor_task_id,
                        'type' => $dependency->dependency_type->value ?? $dependency->dependency_type,
                        'type_label' => method_exists($dependency->dependency_type, 'label')
                            ? $dependency->dependency_type->label()
                            : '',
                        'lag_days' => $dependency->lag_days ?? 0,
                        'is_critical' => $dependency->is_critical ?? false,
                        'is_active' => $dependency->is_active ?? true,
                        'gantt_line' => $this->getDependencyLineCoordinates($allTasks, $dependency),
                    ];
                })
            ),
            'gantt_meta' => [
                'date_range' => $this->getDateRange($schedule, $allTasks),
                'current_date' => now()->format('Y-m-d'),
                'view_mode' => 'days',
                'timeline' => $this->generateTimeline($schedule, $allTasks),
                'critical_path_tasks' => $allTasks
                    ->where('is_critical', true)
                    ->pluck('id')
                    ->toArray(),
            ],
            'display_settings' => $displaySettings,
        ];
    }

    /**
     * @param Collection<int, ScheduleTask> $allTasks
     * @return Collection<int, ScheduleTask>
     */
    protected function buildTaskTree(Collection $allTasks): Collection
    {
        $tasks = $allTasks->sortBy('sort_order')->values();
        $childrenByParent = $tasks->groupBy(
            fn (ScheduleTask $task) => $task->parent_task_id === null ? 'root' : (string) $task->parent_task_id
        );

        $attachChildren = function (?int $parentTaskId) use (&$attachChildren, $childrenByParent): Collection {
            /** @var Collection<int, ScheduleTask> $children */
            $children = $childrenByParent->get($parentTaskId === null ? 'root' : (string) $parentTaskId, collect());

            return $children
                ->sortBy('sort_order')
                ->values()
                ->map(function (ScheduleTask $task) use (&$attachChildren) {
                    $task->setRelation('childTasks', $attachChildren($task->id));

                    return $task;
                });
        };

        return $attachChildren(null);
    }

    /**
     * @param Collection<int, ScheduleTask>|null $allTasks
     * @return array{start: string, end: string}
     */
    protected function getDateRange(ProjectSchedule $schedule, ?Collection $allTasks = null): array
    {
        $allTasks ??= $schedule->relationLoaded('tasks') ? $schedule->tasks : collect();

        if ($allTasks->isEmpty()) {
            return [
                'start' => $schedule->planned_start_date?->format('Y-m-d') ?? now()->format('Y-m-d'),
                'end' => $schedule->planned_end_date?->format('Y-m-d') ?? now()->addMonths(3)->format('Y-m-d'),
            ];
        }

        $minDate = $allTasks->min(
            fn (ScheduleTask $task) => $task->planned_start_date ?? $task->actual_start_date ?? $task->early_start_date
        );
        $maxDate = $allTasks->max(
            fn (ScheduleTask $task) => $task->planned_end_date ?? $task->actual_end_date ?? $task->late_finish_date
        );

        return [
            'start' => $minDate?->format('Y-m-d') ?? $schedule->planned_start_date?->format('Y-m-d') ?? now()->format('Y-m-d'),
            'end' => $maxDate?->format('Y-m-d') ?? $schedule->planned_end_date?->format('Y-m-d') ?? now()->addMonths(3)->format('Y-m-d'),
        ];
    }

    /**
     * @param Collection<int, ScheduleTask>|null $allTasks
     * @return array<int, array{date: string, day: int, month: int, year: int, month_name: string, is_weekend: bool, is_today: bool}>
     */
    protected function generateTimeline(ProjectSchedule $schedule, ?Collection $allTasks = null): array
    {
        $dateRange = $this->getDateRange($schedule, $allTasks);
        $start = Carbon::parse($dateRange['start']);
        $end = Carbon::parse($dateRange['end']);
        $timeline = [];
        $current = $start->copy();

        while ($current <= $end) {
            $timeline[] = [
                'date' => $current->format('Y-m-d'),
                'day' => $current->day,
                'month' => $current->month,
                'year' => $current->year,
                'month_name' => $current->locale('ru')->monthName,
                'is_weekend' => $current->isWeekend(),
                'is_today' => $current->isToday(),
            ];
            $current->addDay();
        }

        return $timeline;
    }

    /**
     * @param Collection<int, ScheduleTask> $allTasks
     * @return array{
     *     from: array{task_id: int, date: string},
     *     to: array{task_id: int, date: string},
     *     lag_days: int
     * }|null
     */
    protected function getDependencyLineCoordinates(Collection $allTasks, TaskDependency $dependency): ?array
    {
        $predecessor = $allTasks->firstWhere('id', $dependency->predecessor_task_id);
        $successor = $allTasks->firstWhere('id', $dependency->successor_task_id);

        if (!$predecessor instanceof ScheduleTask || !$successor instanceof ScheduleTask) {
            return null;
        }

        $predEnd = $predecessor->actual_end_date ?? $predecessor->planned_end_date ?? $predecessor->early_finish_date;
        $succStart = $successor->actual_start_date ?? $successor->planned_start_date ?? $successor->early_start_date;

        if ($predEnd === null || $succStart === null) {
            return null;
        }

        return [
            'from' => [
                'task_id' => $dependency->predecessor_task_id,
                'date' => $predEnd->format('Y-m-d'),
            ],
            'to' => [
                'task_id' => $dependency->successor_task_id,
                'date' => $succStart->format('Y-m-d'),
            ],
            'lag_days' => $dependency->lag_days ?? 0,
        ];
    }
}
