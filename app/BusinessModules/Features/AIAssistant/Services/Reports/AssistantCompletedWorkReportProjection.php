<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\Models\CompletedWork;
use App\Models\EstimateItem;
use App\Models\MeasurementUnit;
use App\Models\ScheduleTask;
use App\Models\WorkType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

final class AssistantCompletedWorkReportProjection
{
    public const DEPENDENCY_COLUMNS = [
        'schedule_task' => ['_report_work_task_id' => 'report_work_task.id'],
        'estimate_item' => ['_report_work_item_id' => 'report_work_item.id', '_report_work_task_item_id' => 'report_work_task_item.id'],
        'work_type' => ['_report_work_type_id' => 'report_work_type.id'],
        'measurement_unit' => ['_report_work_task_unit_id' => 'report_work_task_unit.id', '_report_work_task_item_unit_id' => 'report_work_task_item_unit.id',
            '_report_work_item_unit_id' => 'report_work_item_unit.id', '_report_work_type_unit_id' => 'report_work_type_unit.id'],
    ];

    public function apply(Builder $works, int $organizationId, array $columns, array $related): Builder
    {
        $tasks = ($related['schedule_task'] ?? ScheduleTask::query()->whereRaw('1 = 0'))
            ->join('project_schedules', 'project_schedules.id', '=', 'schedule_tasks.schedule_id')
            ->where('schedule_tasks.organization_id', $organizationId)->where('project_schedules.organization_id', $organizationId)
            ->whereNull('project_schedules.deleted_at')->select(['schedule_tasks.id', 'schedule_tasks.name',
                'schedule_tasks.measurement_unit_id', 'schedule_tasks.estimate_item_id', 'project_schedules.project_id']);
        $items = ($related['estimate_item'] ?? EstimateItem::query()->whereRaw('1 = 0'))
            ->join('estimates', 'estimates.id', '=', 'estimate_items.estimate_id')
            ->where('estimates.organization_id', $organizationId)->whereNull('estimates.deleted_at')
            ->select(['estimate_items.id', 'estimate_items.name', 'estimate_items.measurement_unit_id', 'estimates.project_id']);
        $types = ($related['work_type'] ?? WorkType::query()->whereRaw('1 = 0'))
            ->where(static fn (Builder $query): Builder => $query->where('work_types.organization_id', $organizationId)->orWhereNull('work_types.organization_id'))
            ->select(['work_types.id', 'work_types.name', 'work_types.measurement_unit_id']);
        $units = ($related['measurement_unit'] ?? MeasurementUnit::query()->whereRaw('1 = 0'))
            ->where(static fn (Builder $query): Builder => $query->where('measurement_units.organization_id', $organizationId)->orWhere('measurement_units.is_system', true))
            ->select(['measurement_units.id', 'measurement_units.short_name']);
        $works->scopes(['physicalFacts'])->select(array_map(
            static fn (string $column): string => 'completed_works.'.$column,
            array_values(array_diff($columns, ['quantity'])),
        ))->selectRaw(CompletedWork::EFFECTIVE_QUANTITY_SQL.' AS quantity')
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
        foreach (self::DEPENDENCY_COLUMNS as $dependencies) {
            foreach ($dependencies as $alias => $column) {
                $works->selectRaw($column.' AS '.$alias);
            }
        }

        return $works->getModel()->newModelQuery()->fromSub($works, 'completed_works');
    }
}
