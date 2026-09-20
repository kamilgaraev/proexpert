<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeAcceptanceMapping;
use App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeStatement;
use App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeStatementLine;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\PerformanceActLine;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class WorkVolumeAcceptedAllocationService
{
    public function __construct(private readonly AuthorizationService $authorization, private readonly UserProjectAccessService $access) {}

    public function history(User $actor, int $projectId, int $sourceLineId, int $perPage = 25): LengthAwarePaginator
    {
        $organizationId = (int) $actor->current_organization_id;
        $this->assertProjectAccess($actor, $projectId, $organizationId, 'budget-estimates.view');
        $sourceExists = DB::table('performance_act_lines as source')
            ->join('contract_performance_acts as act', 'act.id', '=', 'source.performance_act_id')
            ->join('contracts as contract', 'contract.id', '=', 'act.contract_id')
            ->where('source.id', $sourceLineId)->where('act.project_id', $projectId)
            ->where('contract.project_id', $projectId)->where('contract.organization_id', $organizationId)->exists();
        if (! $sourceExists) {
            throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.scope_invalid'), 404);
        }
        return WorkVolumeAcceptanceMapping::query()->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->where('performance_act_line_id', $sourceLineId)->where('sealed', true)->with('allocations')
            ->orderByDesc('revision')->paginate(min(max($perPage, 1), 100));
    }

    public function mapActLine(User $actor, int $projectId, int $sourceLineId, array $rows, string $reason, string $operationKey, int $expectedRevision = 0): WorkVolumeAcceptanceMapping
    {
        $organizationId = (int) $actor->current_organization_id;
        $this->assertProjectAccess($actor, $projectId, $organizationId, 'budget-estimates.approve');
        if (trim($reason) === '' || trim($operationKey) === '' || strlen($operationKey) > 128 || count($rows) > 1000) {
            throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.mapping_invalid'), 422);
        }
        $hash = hash('sha256', json_encode([$actor->id, $sourceLineId, $rows, $reason, $expectedRevision], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $projectId, $organizationId, $sourceLineId, $rows, $reason, $operationKey, $expectedRevision, $hash): WorkVolumeAcceptanceMapping {
            Project::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $replay = WorkVolumeAcceptanceMapping::query()->where('organization_id', $organizationId)->where('project_id', $projectId)->where('operation_key', $operationKey)->first();
            if ($replay !== null) {
                if ($replay->operation_hash !== $hash || ! $replay->sealed) {
                    throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.operation_conflict'), 409);
                }
                return $replay->load('allocations');
            }
            $source = PerformanceActLine::query()->find($sourceLineId);
            $act = $source === null ? null : ContractPerformanceAct::query()->find($source->performance_act_id);
            $contract = $act === null ? null : Contract::query()->whereKey($act->contract_id)->where('organization_id', $organizationId)->where('project_id', $projectId)->lockForUpdate()->first();
            if ($source === null || $act === null || $contract === null || (int) $act->project_id !== $projectId) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.scope_invalid'), 404);
            }
            $act = ContractPerformanceAct::query()->whereKey($act->id)->lockForUpdate()->firstOrFail();
            $source = PerformanceActLine::query()->whereKey($sourceLineId)->lockForUpdate()->firstOrFail();
            if (! in_array($act->status, [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED], true)
                || $act->annulled_at !== null || $source->line_type !== PerformanceActLine::TYPE_COMPLETED_WORK
                || ! CompletedWork::query()->physicalFacts()->whereKey($source->completed_work_id)->where('organization_id', $organizationId)->where('project_id', $projectId)->exists()
                || trim((string) $source->unit) === '') {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.mapping_source_invalid'), 422);
            }
            $revision = (int) WorkVolumeAcceptanceMapping::query()->where('performance_act_line_id', $sourceLineId)->where('sealed', true)->max('revision');
            if ($revision !== $expectedRevision) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.mapping_revision_conflict'), 409);
            }
            $sourceQuantity = BigDecimal::of((string) $source->quantity);
            $total = BigDecimal::zero();
            $normalized = [];
            $seen = [];
            foreach ($rows as $row) {
                $quantity = (string) ($row['quantity'] ?? '');
                if (preg_match('/^\d{1,18}(?:\.\d{1,6})?$/D', $quantity) !== 1 || BigDecimal::of($quantity)->isLessThanOrEqualTo(0)) {
                    throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.mapping_invalid'), 422);
                }
                $line = WorkVolumeStatementLine::query()->with('statement')->find((int) ($row['statement_line_id'] ?? 0));
                if ($line === null || (int) $line->statement->organization_id !== $organizationId || (int) $line->statement->project_id !== $projectId) {
                    throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.scope_invalid'), 404);
                }
                $identity = $line->statement->statement_key.':'.$line->line_key;
                if (isset($seen[$identity]) || $line->unit_code !== $source->unit
                    || ($line->estimate_item_id !== null && (int) $line->estimate_item_id !== (int) $source->estimate_item_id)) {
                    throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.mapping_invalid'), 422);
                }
                $seen[$identity] = true;
                $total = $total->plus($quantity);
                $normalized[] = [
                    'statement_line_id' => $line->id, 'quantity' => $quantity,
                    'line_snapshot' => $line->only(['line_key', 'name', 'unit_code', 'place', 'basis_revision', 'estimate_item_id'])
                        + ['statement_key' => $line->statement->statement_key],
                ];
            }
            if ($sourceQuantity->isLessThanOrEqualTo(0) || $total->isGreaterThan($sourceQuantity)) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.mapping_exceeds_source'), 422);
            }
            $mapping = WorkVolumeAcceptanceMapping::query()->create([
                'organization_id' => $organizationId, 'project_id' => $projectId, 'performance_act_line_id' => $sourceLineId,
                'revision' => $revision + 1, 'operation_key' => $operationKey, 'operation_hash' => $hash,
                'source_quantity' => (string) $sourceQuantity, 'source_unit' => $source->unit,
                'reason' => trim($reason), 'created_by' => $actor->id, 'created_at' => now(),
            ]);
            $mapping->allocations()->createMany($normalized);
            $mapping->forceFill(['sealed' => true])->save();
            return $mapping->load('allocations');
        });
    }

    public function protectedLines(WorkVolumeStatement $statement): array
    {
        $rows = DB::table('work_volume_accepted_allocations as allocation')
            ->join('work_volume_acceptance_mappings as mapping', 'mapping.id', '=', 'allocation.mapping_id')
            ->join('performance_act_lines as source', 'source.id', '=', 'mapping.performance_act_line_id')
            ->join('contract_performance_acts as act', 'act.id', '=', 'source.performance_act_id')
            ->where('mapping.organization_id', $statement->organization_id)->where('mapping.project_id', $statement->project_id)
            ->where('mapping.sealed', true)->whereNull('act.annulled_at')
            ->whereIn('act.status', [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED])
            ->where('allocation.line_snapshot->statement_key', $statement->statement_key)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('work_volume_acceptance_mappings as newer')
                ->whereColumn('newer.performance_act_line_id', 'mapping.performance_act_line_id')->where('newer.sealed', true)
                ->whereColumn('newer.revision', '>', 'mapping.revision'))
            ->get(['allocation.quantity', 'allocation.line_snapshot']);
        $protected = [];
        foreach ($rows as $row) {
            $snapshot = json_decode($row->line_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $key = $snapshot['line_key'];
            $protected[$key]['quantity'] = (string) BigDecimal::of($protected[$key]['quantity'] ?? '0')->plus((string) $row->quantity);
            $protected[$key]['identities'][] = $snapshot;
        }
        return $protected;
    }

    public function assertSourcesMapped(WorkVolumeStatement $statement): void
    {
        $basisLines = WorkVolumeStatementLine::query()->whereHas('statement', fn ($query) => $query
            ->where('organization_id', $statement->organization_id)->where('project_id', $statement->project_id)
            ->where('statement_key', $statement->statement_key));
        $hasUnspecifiedBasis = (clone $basisLines)->whereNull('estimate_item_id')->exists();
        $itemIds = (clone $basisLines)->whereNotNull('estimate_item_id')->distinct()->pluck('estimate_item_id')->all();
        $acceptedActs = DB::table('contract_performance_acts as act')
            ->join('contracts as contract', 'contract.id', '=', 'act.contract_id')
            ->where('contract.organization_id', $statement->organization_id)->where('contract.project_id', $statement->project_id)
            ->where('act.project_id', $statement->project_id)->whereNull('act.annulled_at')
            ->whereIn('act.status', [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED]);
        $legacy = (clone $acceptedActs)->join('performance_act_completed_works as legacy', 'legacy.performance_act_id', '=', 'act.id')
            ->join('completed_works as work', 'work.id', '=', 'legacy.completed_work_id')
            ->where('work.organization_id', $statement->organization_id)->where('work.project_id', $statement->project_id)
            ->where('legacy.included_quantity', '>', 0)
            ->when(! $hasUnspecifiedBasis, fn ($query) => $query->where(fn ($basis) => $basis->whereIn('work.estimate_item_id', $itemIds)->orWhereNull('work.estimate_item_id')))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('performance_act_lines')->whereColumn('performance_act_id', 'act.id'))
            ->exists();
        $manual = (clone $acceptedActs)->join('performance_act_lines as source', 'source.performance_act_id', '=', 'act.id')
            ->when(! $hasUnspecifiedBasis, fn ($query) => $query->where(fn ($basis) => $basis->whereIn('source.estimate_item_id', $itemIds)->orWhereNull('source.estimate_item_id')))
            ->where(function ($query): void {
                $query->where('source.line_type', PerformanceActLine::TYPE_MANUAL)->orWhereNull('source.completed_work_id');
            })->exists();
        $execution = (clone $acceptedActs)->join('estimate_finance_execution_allocations as fact', 'fact.performance_act_id', '=', 'act.id')
            ->join('estimate_finance_allocations as conditions', 'conditions.id', '=', 'fact.allocation_id')
            ->where('fact.organization_id', $statement->organization_id)->where('fact.project_id', $statement->project_id)
            ->where('conditions.organization_id', $statement->organization_id)->whereColumn('conditions.contract_id', 'act.contract_id')
            ->whereNull('conditions.resource_id')
            ->when(! $hasUnspecifiedBasis, fn ($query) => $query->whereIn('conditions.estimate_item_id', $itemIds))
            ->where(fn ($query) => $query->where('fact.quantity', '>', 0)->orWhere(fn ($unknown) => $unknown->whereNull('fact.quantity')->where('fact.amount_with_vat', '>', 0)))
            ->exists();
        if ($legacy || $manual || $execution) {
            throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.accepted_source_unmapped'), 409);
        }
        $totals = DB::table('work_volume_acceptance_mappings as mapping')
            ->join('work_volume_accepted_allocations as allocation', 'allocation.mapping_id', '=', 'mapping.id')
            ->where('mapping.organization_id', $statement->organization_id)->where('mapping.project_id', $statement->project_id)
            ->where('mapping.sealed', true)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('work_volume_acceptance_mappings as newer')
                ->whereColumn('newer.performance_act_line_id', 'mapping.performance_act_line_id')->where('newer.sealed', true)
                ->whereColumn('newer.revision', '>', 'mapping.revision'))
            ->selectRaw('mapping.performance_act_line_id, SUM(allocation.quantity) AS quantity')
            ->groupBy('mapping.performance_act_line_id');
        $physical = CompletedWork::withTrashed()->physicalFacts()
            ->whereColumn('completed_works.id', 'source.completed_work_id')
            ->where('completed_works.organization_id', $statement->organization_id)->where('completed_works.project_id', $statement->project_id)
            ->selectRaw('1')->toBase();
        $unmapped = DB::table('performance_act_lines as source')
            ->join('contract_performance_acts as act', 'act.id', '=', 'source.performance_act_id')
            ->join('contracts as contract', 'contract.id', '=', 'act.contract_id')
            ->leftJoinSub($totals, 'mapped', fn ($join) => $join->on('mapped.performance_act_line_id', '=', 'source.id'))
            ->where('contract.organization_id', $statement->organization_id)->where('contract.project_id', $statement->project_id)
            ->where('act.project_id', $statement->project_id)->whereNull('act.annulled_at')
            ->whereIn('act.status', [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED])
            ->where('source.line_type', PerformanceActLine::TYPE_COMPLETED_WORK)->whereExists($physical)
            ->when(! $hasUnspecifiedBasis, fn ($query) => $query->where(fn ($basis) => $basis->whereIn('source.estimate_item_id', $itemIds)->orWhereNull('source.estimate_item_id')))
            ->whereRaw('COALESCE(mapped.quantity, 0) < source.quantity')->exists();
        if ($unmapped) {
            throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.accepted_source_unmapped'), 409);
        }
    }

    private function assertProjectAccess(User $actor, int $projectId, int $organizationId, string $permission): void
    {
        $project = Project::query()->find($projectId);
        if ($organizationId <= 0 || $project === null || ! $actor->belongsToOrganization($organizationId) || ! $this->access->canAccessProject($actor, $project, $organizationId)) {
            throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.scope_invalid'), 404);
        }
        if (! $this->authorization->can($actor, $permission, ['organization_id' => $organizationId, 'project_id' => $projectId, 'strict_project_scope' => true])) {
            throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.forbidden'), 403);
        }
    }
}
