<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeStatement;
use App\Models\CompletedWork;
use App\Models\PerformanceActLine;
use App\Services\Acting\ActingQuantityStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class WorkVolumeCoverageTotals
{
    public function otherCoverage(WorkVolumeStatement $statement, array $contractIds): Collection
    {
        return DB::table('work_volume_statement_coverages as coverage')
            ->join('work_volume_statements as statement', 'statement.id', '=', 'coverage.statement_id')
            ->where('statement.organization_id', $statement->organization_id)->where('statement.project_id', $statement->project_id)
            ->where('statement.status', WorkVolumeStatement::STATUS_APPROVED)->where('statement.id', '!=', $statement->id)
            ->whereIn('coverage.contract_id', $contractIds)
            ->whereRaw('coverage.coverage_revision = (SELECT MAX(revision) FROM work_volume_coverage_revisions WHERE statement_id = coverage.statement_id)')
            ->selectRaw('coverage.contract_id, coverage.estimate_item_id, coverage.estimate_unit_code AS unit, SUM(coverage.estimate_quantity) AS quantity')
            ->groupBy('coverage.contract_id', 'coverage.estimate_item_id', 'coverage.estimate_unit_code')->get()
            ->groupBy(fn ($row): string => $row->contract_id.':'.$row->estimate_item_id);
    }

    public function reservations(WorkVolumeStatement $statement, array $contractIds): Collection
    {
        $physical = CompletedWork::withTrashed()->physicalFacts()->whereColumn('completed_works.id', 'work.id')
            ->where('completed_works.organization_id', $statement->organization_id)->where('completed_works.project_id', $statement->project_id)
            ->selectRaw('1')->toBase();
        $native = DB::table('performance_act_lines as line')
            ->join('contract_performance_acts as act', 'act.id', '=', 'line.performance_act_id')
            ->join('completed_works as work', 'work.id', '=', 'line.completed_work_id')
            ->whereIn('act.contract_id', $contractIds)->where('act.project_id', $statement->project_id)
            ->whereNull('act.annulled_at')->whereNotIn('act.status', ActingQuantityStatus::releasedStatuses())
            ->where('line.line_type', PerformanceActLine::TYPE_COMPLETED_WORK)->whereExists($physical)
            ->selectRaw('act.contract_id, COALESCE(line.estimate_item_id, work.estimate_item_id) AS estimate_item_id, line.unit, SUM(line.quantity) AS quantity')
            ->groupBy('act.contract_id', 'line.estimate_item_id', 'work.estimate_item_id', 'line.unit');
        $legacy = DB::table('performance_act_completed_works as pivot')
            ->join('contract_performance_acts as act', 'act.id', '=', 'pivot.performance_act_id')
            ->join('completed_works as work', 'work.id', '=', 'pivot.completed_work_id')
            ->leftJoin('work_types as type', 'type.id', '=', 'work.work_type_id')
            ->leftJoin('measurement_units as unit', 'unit.id', '=', 'type.measurement_unit_id')
            ->whereIn('act.contract_id', $contractIds)->where('act.project_id', $statement->project_id)
            ->whereNull('act.annulled_at')->whereNotIn('act.status', ActingQuantityStatus::releasedStatuses())
            ->whereExists(clone $physical)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('performance_act_lines as canonical')
                ->whereColumn('canonical.performance_act_id', 'pivot.performance_act_id')
                ->whereColumn('canonical.completed_work_id', 'pivot.completed_work_id')
                ->where('canonical.line_type', PerformanceActLine::TYPE_COMPLETED_WORK))
            ->selectRaw('act.contract_id, work.estimate_item_id, unit.short_name AS unit, SUM(pivot.included_quantity) AS quantity')
            ->groupBy('act.contract_id', 'work.estimate_item_id', 'unit.short_name');
        return $native->unionAll($legacy)->get()->groupBy(fn ($row): string => $row->contract_id.':'.$row->estimate_item_id);
    }
}
