<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

final class AssistantScheduleTaskReportProjection
{
    public function apply(Builder $tasks, Builder $schedules): Builder
    {
        $overdue = (clone $tasks)->overdue()->select('schedule_tasks.id');
        $tasks->select('schedule_tasks.*')
            ->joinSub($schedules->select(['project_schedules.id', 'project_schedules.project_id']), 'report_task_schedule',
                static fn (JoinClause $join) => $join->on('report_task_schedule.id', '=', 'schedule_tasks.schedule_id'))
            ->addSelect('report_task_schedule.project_id')
            ->selectRaw('CASE WHEN schedule_tasks.id IN ('.$overdue->toSql().') THEN TRUE ELSE FALSE END AS is_overdue', $overdue->getBindings());

        return $tasks->getModel()->newQuery()->fromSub($tasks, 'schedule_tasks');
    }
}
