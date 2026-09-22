<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\ContractPerformanceAct;
use App\Models\CompletedWork;
use App\Models\PerformanceActLine;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use App\Exceptions\BusinessLogicException;

final class WorkVolumeUnmappedFactsQuery
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly UserProjectAccessService $access,
    ) {}

    public function paginate(User $actor, int $projectId, int $perPage = 25): LengthAwarePaginator
    {
        $project = Project::query()->find($projectId);
        $organizationId = (int) $actor->current_organization_id;
        if ($project === null || $organizationId <= 0
            || ! $actor->belongsToOrganization($organizationId)
            || ! $this->access->canAccessProject($actor, $project, $organizationId)) {
            throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.scope_invalid'), 404);
        }
        if (! $this->authorization->can($actor, 'budget-estimates.view', ['project_id' => $projectId, 'organization_id' => $organizationId, 'strict_project_scope' => true])) {
            throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.forbidden'), 403);
        }

        $query = DB::query()->fromSub($this->sources($organizationId, $projectId), 'unmapped')
            ->select('unmapped.*')
            ->selectRaw('CAST(source_quantity - mapped_quantity AS numeric(24,6)) as unmapped_quantity')
            ->where(function (Builder $query): void {
                $query->whereRaw('source_quantity > mapped_quantity')
                    ->orWhere('reason', 'execution_without_physical_source');
            })
            ->orderByDesc('act_date')->orderBy('source_kind')->orderBy('source_id');

        return $query->paginate(min(max($perPage, 1), 100));
    }

    private function sources(int $organizationId, int $projectId): Builder
    {
        $accepted = fn (Builder $query): Builder => $query
            ->whereIn('act.status', [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED])
            ->whereNull('act.annulled_at');

        $native = DB::table('performance_act_lines as line')
            ->join('contract_performance_acts as act', 'act.id', '=', 'line.performance_act_id')
            ->join('contracts as contract', 'contract.id', '=', 'act.contract_id')
            ->leftJoin('completed_works as work', 'work.id', '=', 'line.completed_work_id')
            ->where('contract.organization_id', $organizationId)->where('contract.project_id', $projectId)
            ->where('act.project_id', $projectId)->where('line.line_type', PerformanceActLine::TYPE_COMPLETED_WORK)
            ->whereNotNull('line.completed_work_id')->where('work.organization_id', $organizationId)->where('work.project_id', $projectId)
            ->whereExists(CompletedWork::withTrashed()->physicalFacts()
                ->whereColumn('completed_works.id', 'line.completed_work_id')
                ->where('completed_works.organization_id', $organizationId)
                ->where('completed_works.project_id', $projectId)
                ->selectRaw('1')->toBase())
            ->where($accepted)
            ->selectRaw("'native' as source_kind, line.id as source_id, act.id as act_id, act.act_document_number as act_number, act.act_date, contract.id as contract_id, contract.number as contract_number, COALESCE(line.estimate_item_id, work.estimate_item_id) as estimate_item_id, line.unit, CAST(line.quantity AS numeric(24,6)) as source_quantity, CAST(COALESCE((SELECT SUM(allocation.source_quantity) FROM work_volume_accepted_allocations allocation JOIN work_volume_acceptance_mappings mapping ON mapping.id = allocation.mapping_id WHERE mapping.performance_act_line_id = line.id AND mapping.sealed = true AND mapping.revision = (SELECT MAX(newer.revision) FROM work_volume_acceptance_mappings newer WHERE newer.performance_act_line_id = line.id AND newer.sealed = true)), 0) AS numeric(24,6)) as mapped_quantity, 'native_remaining' as reason");

        $manual = DB::table('performance_act_lines as line')
            ->join('contract_performance_acts as act', 'act.id', '=', 'line.performance_act_id')
            ->join('contracts as contract', 'contract.id', '=', 'act.contract_id')
            ->leftJoin('estimate_items as item', 'item.id', '=', 'line.estimate_item_id')
            ->where('contract.organization_id', $organizationId)->where('contract.project_id', $projectId)
            ->where('act.project_id', $projectId)
            ->where(fn (Builder $query): Builder => $query->where('line.line_type', PerformanceActLine::TYPE_MANUAL)->orWhereNull('line.completed_work_id'))
            ->where($accepted)
            ->selectRaw("'manual_or_unlinked_native' as source_kind, line.id as source_id, act.id as act_id, act.act_document_number as act_number, act.act_date, contract.id as contract_id, contract.number as contract_number, line.estimate_item_id, line.unit, CAST(line.quantity AS numeric(24,6)) as source_quantity, CAST(0 AS numeric(24,6)) as mapped_quantity, 'manual_or_unlinked_native' as reason");

        $legacy = DB::table('performance_act_completed_works as pivot')
            ->join('contract_performance_acts as act', 'act.id', '=', 'pivot.performance_act_id')
            ->join('contracts as contract', 'contract.id', '=', 'act.contract_id')
            ->join('completed_works as work', 'work.id', '=', 'pivot.completed_work_id')
            ->leftJoin('work_types as type', 'type.id', '=', 'work.work_type_id')
            ->leftJoin('measurement_units as unit', 'unit.id', '=', 'type.measurement_unit_id')
            ->where('contract.organization_id', $organizationId)->where('contract.project_id', $projectId)
            ->where('act.project_id', $projectId)->where('work.organization_id', $organizationId)->where('work.project_id', $projectId)->where($accepted)
            ->whereExists(CompletedWork::withTrashed()->physicalFacts()
                ->whereColumn('completed_works.id', 'pivot.completed_work_id')
                ->where('completed_works.organization_id', $organizationId)
                ->where('completed_works.project_id', $projectId)
                ->selectRaw('1')->toBase())
            ->whereNotExists(fn (Builder $query): Builder => $query->selectRaw('1')->from('performance_act_lines as canonical')->whereColumn('canonical.performance_act_id', 'pivot.performance_act_id')->whereColumn('canonical.completed_work_id', 'pivot.completed_work_id')->where('canonical.line_type', PerformanceActLine::TYPE_COMPLETED_WORK))
            ->selectRaw("'legacy' as source_kind, pivot.id as source_id, act.id as act_id, act.act_document_number as act_number, act.act_date, contract.id as contract_id, contract.number as contract_number, work.estimate_item_id, unit.short_name as unit, CAST(pivot.included_quantity AS numeric(24,6)) as source_quantity, CAST(0 AS numeric(24,6)) as mapped_quantity, 'legacy_without_native_canonical' as reason");

        $execution = DB::table('estimate_finance_execution_allocations as fact')
            ->join('contract_performance_acts as act', 'act.id', '=', 'fact.performance_act_id')
            ->join('contracts as contract', 'contract.id', '=', 'act.contract_id')
            ->join('estimate_finance_allocations as finance', 'finance.id', '=', 'fact.allocation_id')
            ->join('estimates as finance_estimate', 'finance_estimate.id', '=', 'finance.estimate_id')
            ->leftJoin('estimate_items as item', 'item.id', '=', 'finance.estimate_item_id')
            ->leftJoin('measurement_units as unit', 'unit.id', '=', 'item.measurement_unit_id')
            ->where('fact.organization_id', $organizationId)->where('fact.project_id', $projectId)
            ->where('contract.organization_id', $organizationId)->where('contract.project_id', $projectId)->where('act.project_id', $projectId)
            ->where('finance.organization_id', $organizationId)->where('finance_estimate.organization_id', $organizationId)->where('finance_estimate.project_id', $projectId)->whereColumn('finance.contract_id', 'act.contract_id')->whereNull('finance.resource_id')
            ->whereColumn('item.estimate_id', 'finance.estimate_id')
            ->where($accepted)
            ->where(fn (Builder $query): Builder => $query->where('fact.quantity', '>', 0)->orWhere(fn (Builder $nested): Builder => $nested->whereNull('fact.quantity')->where('fact.amount_with_vat', '>', 0)))
            ->selectRaw("'finance_execution' as source_kind, fact.id as source_id, act.id as act_id, act.act_document_number as act_number, act.act_date, contract.id as contract_id, contract.number as contract_number, finance.estimate_item_id, unit.short_name as unit, CAST(fact.quantity AS numeric(24,6)) as source_quantity, CAST(0 AS numeric(24,6)) as mapped_quantity, 'execution_without_physical_source' as reason");

        return $native->unionAll($manual)->unionAll($legacy)->unionAll($execution);
    }
}
