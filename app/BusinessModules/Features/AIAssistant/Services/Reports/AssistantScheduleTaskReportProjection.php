<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

final class AssistantScheduleTaskReportProjection
{
    public function apply(Builder $tasks, Builder $schedules, int $organizationId): Builder
    {
        $overdue = (clone $tasks)->overdue()->select('schedule_tasks.id');
        $assignee = User::query()->select('users.name')->whereColumn('users.id', 'schedule_tasks.assigned_user_id')
            ->where('users.is_active', true)->whereHas('organizations', static fn (Builder $organizations): Builder => $organizations->where('organizations.id', $organizationId)->where('organization_user.is_active', true))->limit(1);
        $tasks->select('schedule_tasks.*')
            ->joinSub($schedules->select(['project_schedules.id', 'project_schedules.project_id']), 'report_task_schedule',
                static fn (JoinClause $join) => $join->on('report_task_schedule.id', '=', 'schedule_tasks.schedule_id'))
            ->addSelect('report_task_schedule.project_id')
            ->selectRaw('CASE WHEN schedule_tasks.assigned_user_id IS NULL THEN ? ELSE ('.$assignee->toSql().') END AS assigned_user_name',
                [trans_message('ai_assistant_attention.owner_unassigned'), ...$assignee->getBindings()])
            ->selectRaw('CASE WHEN schedule_tasks.id IN ('.$overdue->toSql().') THEN TRUE ELSE FALSE END AS is_overdue', $overdue->getBindings());

        return $tasks->getModel()->newQuery()->fromSub($tasks, 'schedule_tasks');
    }
}
