<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\Models\Material;
use App\Models\MeasurementUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

final class AssistantWarehouseMovementReportProjection
{
    public const DEPENDENCY_COLUMNS = [
        'material' => ['_report_movement_material_id' => 'report_movement_material.id'],
        'measurement_unit' => ['_report_movement_unit_id' => 'report_movement_unit.id'],
    ];

    public function apply(Builder $movements, int $organizationId, array $columns, ?Builder $materials, ?Builder $units): Builder
    {
        $materials = ($materials ?? Material::query()->whereRaw('1 = 0'))
            ->where('materials.organization_id', $organizationId)->select(['materials.id', 'materials.measurement_unit_id']);
        $units = ($units ?? MeasurementUnit::query()->whereRaw('1 = 0'))
            ->where('measurement_units.organization_id', $organizationId)
            ->select(['measurement_units.id', 'measurement_units.short_name', 'measurement_units.name']);
        $table = $movements->getModel()->getTable();
        $movements->select(array_map(static fn (string $column): string => $table.'.'.$column, array_values(array_diff($columns, ['movement_date']))))
            ->leftJoinSub($materials, 'report_movement_material', static fn (JoinClause $join) => $join
                ->on('report_movement_material.id', '=', $table.'.material_id'))
            ->leftJoinSub($units, 'report_movement_unit', static fn (JoinClause $join) => $join
                ->on('report_movement_unit.id', '=', 'report_movement_material.measurement_unit_id'))
            ->selectRaw('COALESCE(report_movement_unit.short_name, report_movement_unit.name) AS unit');
        if (in_array('movement_date', $columns, true)) {
            $date = $movements->getQuery()->getGrammar()->wrap($table.'.movement_date');
            $movements->selectRaw('('.$date.' AT TIME ZONE ? AT TIME ZONE ?) AS movement_date',
                [(string) config('app.timezone', 'UTC'), 'Europe/Moscow']);
        }
        foreach (self::DEPENDENCY_COLUMNS as $dependencies) {
            foreach ($dependencies as $alias => $column) {
                $movements->selectRaw($column.' AS '.$alias);
            }
        }

        return $movements->getModel()->newModelQuery()->fromSub($movements, $table);
    }
}
