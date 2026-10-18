<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\Models\EstimateItem;
use App\Models\MeasurementUnit;
use App\Models\ScheduleTask;
use App\Models\WorkType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

final class AssistantCompletedWorkReportProjection
{
    public function apply(Builder $works, int $organizationId): Builder
    {
        $tasks = ScheduleTask::query()->join('project_schedules', 'project_schedules.id', '=', 'schedule_tasks.schedule_id')
            ->where('schedule_tasks.organization_id', $organizationId)->where('project_schedules.organization_id', $organizationId)
            ->whereNull('project_schedules.deleted_at')->select(['schedule_tasks.id', 'schedule_tasks.name',
                'schedule_tasks.measurement_unit_id', 'schedule_tasks.estimate_item_id', 'project_schedules.project_id']);
        $items = EstimateItem::query()->join('estimates', 'estimates.id', '=', 'estimate_items.estimate_id')
            ->where('estimates.organization_id', $organizationId)->whereNull('estimates.deleted_at')
            ->select(['estimate_items.id', 'estimate_items.name', 'estimate_items.measurement_unit_id', 'estimates.project_id']);
        $types = WorkType::query()->where(static fn (Builder $query): Builder => $query->where('organization_id', $organizationId)->orWhereNull('organization_id'))
            ->select(['id', 'name', 'measurement_unit_id']);
        $units = MeasurementUnit::query()->where(static fn (Builder $query): Builder => $query->where('organization_id', $organizationId)->orWhere('is_system', true))
            ->select(['id', 'short_name']);
        $works->select('completed_works.*')
            ->leftJoinSub($tasks, 'report_work_task', static fn (JoinClause $join) => $join
                ->on('report_work_task.id', '=', 'completed_works.schedule_task_id')
                ->on('report_work_task.project_id', '=', 'completed_works.project_id'))
            ->leftJoinSub(clone $items, 'report_work_item', static fn (JoinClause $join) => $join
                ->on('report_work_item.id', '=', 'completed_works.estimate_item_id')
                ->on('report_work_item.project_id', '=', 'completed_works.project_id'))
            ->leftJoinSub($items, 'report_work_task_item', static fn (JoinClause $join) => $join
                ->on('report_work_task_item.id', '=', 'report_work_task.estimate_item_id')
                ->on('report_work_task_item.project_id', '=', 'completed_works.project_id'))
            ->leftJoinSub($types, 'report_work_type', static fn (JoinClause $join) => $join
                ->on('report_work_type.id', '=', 'completed_works.work_type_id'));
        foreach (['task', 'task_item', 'item', 'type'] as $source) {
            $works->leftJoinSub(clone $units, 'report_work_'.$source.'_unit', static fn (JoinClause $join) => $join
                ->on('report_work_'.$source.'_unit.id', '=', 'report_work_'.$source.'.measurement_unit_id'));
        }
        $works->selectRaw("COALESCE(NULLIF(report_work_task.name, ''), NULLIF(report_work_item.name, ''),
            NULLIF(completed_works.additional_info->>'work_name', ''), report_work_type.name) AS name")
            ->selectRaw("COALESCE(report_work_task_unit.short_name, report_work_task_item_unit.short_name,
                report_work_item_unit.short_name, NULLIF(completed_works.additional_info->>'unit_of_measurement', ''),
                report_work_type_unit.short_name) AS unit");

        return $works->getModel()->newModelQuery()->fromSub($works, 'completed_works');
    }
}
