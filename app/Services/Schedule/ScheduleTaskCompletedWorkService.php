<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Models\ScheduleTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ScheduleTaskCompletedWorkService
{
    public function syncCompletedQuantity(ScheduleTask $task): void
    {
        $total = DB::table('completed_works')
            ->where('schedule_task_id', $task->id)
            ->whereNull('deleted_at')
            ->sum('completed_quantity');

        $task->completed_quantity = (float)$total;
        $task->saveQuietly();

        if ($task->quantity && $task->quantity > 0) {
            $task->recalculateProgressFromQuantity();
        }
    }

    public function getTasksForSelection(int $projectId, ?int $scheduleId = null, ?string $search = null): Collection
    {
        $query = ScheduleTask::query()
            ->where('organization_id', auth()->user()?->current_organization_id)
            ->whereHas('schedule', fn($q) => $q->where('project_id', $projectId))
            ->where('task_type', '!=', 'summary')
            ->where('task_type', '!=', 'container')
            ->with(['schedule:id,name', 'measurementUnit:id,short_name'])
            ->orderBy('sort_order');

        if ($scheduleId) {
            $query->where('schedule_id', $scheduleId);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('wbs_code', 'ilike', "%{$search}%");
            });
        }

        return $query->select([
            'id',
            'schedule_id',
            'name',
            'wbs_code',
            'task_type',
            'quantity',
            'completed_quantity',
            'measurement_unit_id',
            'planned_start_date',
            'planned_end_date',
            'progress_percent',
            'status',
        ])->get();
    }
}
