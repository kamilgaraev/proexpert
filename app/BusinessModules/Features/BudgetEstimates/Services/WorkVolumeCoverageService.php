<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeStatement;
use App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeStatementCoverage;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\EstimateItem;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\User;
use App\Exceptions\BusinessLogicException;
use App\Models\Project;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\WorkVolumeCoverageRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Brick\Math\BigDecimal;
use App\Services\Acting\ActingQuantityStatus;
use App\Models\PerformanceActLine;

final class WorkVolumeCoverageService
{
    public function __construct(private readonly AuthorizationService $authorization, private readonly WorkVolumeStatementService $statements) {}

    public function allocations(User $actor, WorkVolumeStatement $statement): array
    {
        $this->assertScope($actor, $statement, 'budget-estimates.view');
        $revision = (int) DB::table('work_volume_coverage_revisions')->where('statement_id', $statement->id)->max('revision');
        return $this->revisionResult($statement, $revision);
    }

    public function sourceReviews(User $actor, WorkVolumeStatement $statement, int $perPage = 25): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $this->assertScope($actor, $statement, 'budget-estimates.view');
        return \App\BusinessModules\Features\DesignManagement\Models\DesignImpactReview::query()
            ->where('organization_id', $statement->organization_id)->where('project_id', $statement->project_id)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('work_volume_statement_coverages as coverage')
                ->where('coverage.statement_id', $statement->id)
                ->whereRaw('coverage.coverage_revision = (SELECT MAX(revision) FROM work_volume_coverage_revisions WHERE statement_id = coverage.statement_id)')
                ->where(fn ($links) => $links->whereColumn('coverage.source_link_id', 'design_impact_reviews.link_id')
                    ->orWhereRaw("coverage.conversion_basis->>'source_link_id' = design_impact_reviews.link_id::text")))
            ->orderByDesc('id')->paginate(min(max($perPage, 1), 100));
    }

    public function replaceAllocations(User $actor, WorkVolumeStatement $statement, array $rows, string $operationKey, int $expectedRevision): array
    {
        $this->assertScope($actor, $statement, 'budget-estimates.edit');
        if (trim($operationKey) === '' || strlen($operationKey) > 128 || $expectedRevision < 0) {
            throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.coverage_line_invalid'), 422);
        }
        $operationHash = hash('sha256', json_encode([$rows, $expectedRevision], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($actor, $statement, $rows, $operationKey, $expectedRevision, $operationHash): array {
            Project::query()->whereKey($statement->project_id)->lockForUpdate()->firstOrFail();
            $locked = WorkVolumeStatement::query()->whereKey($statement->id)->lockForUpdate()->with('lines')->firstOrFail();
            $replay = DB::table('work_volume_coverage_revisions')->where('statement_id', $locked->id)
                ->where('actor_id', $actor->id)->where('operation_key', $operationKey)->first();
            if ($replay !== null) {
                if (! hash_equals($replay->operation_hash, $operationHash)) {
                    throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.operation_conflict'), 409);
                }
                return $this->revisionResult($locked, (int) $replay->revision);
            }
            $currentRevision = (int) DB::table('work_volume_coverage_revisions')->where('statement_id', $locked->id)->max('revision');
            if ($currentRevision !== $expectedRevision) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.operation_conflict'), 409);
            }
            if (Validator::make(['allocations' => $rows, 'operation_key' => $operationKey, 'expected_coverage_revision' => $expectedRevision], (new WorkVolumeCoverageRequest())->rules())->fails()) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.coverage_line_invalid'), 422);
            }
            if ($locked->status !== WorkVolumeStatement::STATUS_APPROVED) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.coverage_statement_invalid'), 422);
            }
            $lines = $locked->lines->keyBy('id');
            $totalByLine = [];
            $totalByKey = [];
            $targetTotals = $targetLimits = [];
            $normalized = [];
            $seen = [];
            $previousRows = WorkVolumeStatementCoverage::query()->where('statement_id', $locked->id)->where('coverage_revision', $currentRevision)->get();
            $contractIds = array_unique([...array_column($rows, 'contract_id'), ...$previousRows->pluck('contract_id')->all()]);
            $itemIds = array_unique(array_column($rows, 'estimate_item_id'));
            $contracts = Contract::query()->whereIn('id', $contractIds)->where('organization_id', $locked->organization_id)
                ->where('project_id', $locked->project_id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $links = ContractEstimateItem::query()->whereIn('contract_id', $contracts->keys())->whereIn('estimate_item_id', $itemIds)
                ->get()->keyBy(fn ($link): string => $link->contract_id.':'.$link->estimate_id.':'.$link->estimate_item_id);
            $items = EstimateItem::query()->with(['measurementUnit', 'estimate'])->whereIn('id', $itemIds)->get()->keyBy('id');
            $sourceTargets = [];
            foreach ($rows as $row) {
                foreach ([$row['source_link_id'] ?? null, $row['conversion_basis']['source_link_id'] ?? null] as $sourceId) {
                    if ($sourceId === null) {
                        continue;
                    }
                    if (isset($sourceTargets[$sourceId]) && $sourceTargets[$sourceId] !== (int) $row['estimate_item_id']) {
                        throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.coverage_source_invalid'), 422);
                    }
                    $sourceTargets[$sourceId] = (int) $row['estimate_item_id'];
                }
            }
            $sourceEvidence = (new WorkVolumeCoverageSourceEvidence())->resolveMany((int) $locked->organization_id, (int) $locked->project_id, $sourceTargets);
            foreach ($rows as $row) {
                $line = $lines->get((int) $row['statement_line_id']);
                if ($line === null || (string) $line->unit_code !== (string) $row['unit_code']) {
                    throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.coverage_line_invalid'), 422);
                }
                $contract = $contracts->get((int) $row['contract_id']);
                $link = $links->get($row['contract_id'].':'.$row['estimate_id'].':'.$row['estimate_item_id']);
                if ($contract === null || $link === null) {
                    throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.coverage_scope_invalid'), 422);
                }
                $identity = $line->id.':'.$contract->id.':'.$link->estimate_item_id;
                if (isset($seen[$identity])) {
                    throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.coverage_line_invalid'), 422);
                }
                $seen[$identity] = true;
                $quantity = (string) $row['quantity'];
                if (! preg_match('/^[0-9]+(?:\.[0-9]{1,6})?$/D', $quantity) || ! BigDecimal::of($quantity)->isPositive()) {
                    throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.coverage_quantity_invalid'), 422);
                }
                $item = $items->get((int) $link->estimate_item_id);
                if ($item === null || $item->estimate === null || (int) $item->estimate->organization_id !== (int) $locked->organization_id || (int) $item->estimate->project_id !== (int) $locked->project_id || $item->measurementUnit === null || (int) $item->measurementUnit->organization_id !== (int) $locked->organization_id) {
                    throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.coverage_unit_conversion_required'), 422);
                }
                $targetUnit = (string) $item->measurementUnit->short_name;
                if ($targetUnit !== $row['unit_code'] && ! $this->authorization->can($actor, 'budget-estimates.approve', [
                    'organization_id' => $locked->organization_id, 'project_id' => $locked->project_id, 'strict_project_scope' => true,
                ])) {
                    throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.forbidden'), 403);
                }
                $converted = (new WorkVolumeCoverageConversion())->convert($quantity, $row['unit_code'], $targetUnit, $row['conversion_basis'] ?? null);
                $basis = $converted['basis'];
                if ($basis !== null) {
                    $basis += ['confirmed_by_user_id' => $actor->id, 'confirmed_at' => now()->toISOString()];
                }
                $targetKey = $contract->id.':'.$item->id;
                $targetTotals[$targetKey] = $this->add($targetTotals[$targetKey] ?? '0', $converted['quantity']);
                $targetLimits[$targetKey] = ['contract_id' => $contract->id, 'item_id' => $item->id, 'quantity' => (string) $link->quantity, 'unit' => $targetUnit];
                $totalByLine[$line->id] = $this->add($totalByLine[$line->id] ?? '0', $quantity);
                $key = $line->id.':'.$contract->id.':'.$link->estimate_item_id;
                $totalByKey[$key] = $this->add($totalByKey[$key] ?? '0', $quantity);
                if ($this->cmp($totalByLine[$line->id], (string) $line->quantity) > 0) {
                    throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.coverage_exceeds_statement'), 422);
                }
                $normalized[] = [
                    'statement_id' => $locked->id, 'statement_line_id' => $line->id, 'organization_id' => $locked->organization_id, 'project_id' => $locked->project_id,
                    'contract_id' => $contract->id, 'estimate_id' => $link->estimate_id, 'estimate_item_id' => $link->estimate_item_id,
                    'quantity' => $quantity, 'unit_code' => $row['unit_code'], 'source_link_id' => $row['source_link_id'] ?? null, 'conversion_basis' => $basis === null ? null : json_encode($basis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'estimate_quantity' => $converted['quantity'], 'estimate_unit_code' => $targetUnit,
                    'source_snapshot' => empty($row['source_link_id']) && empty($basis['source_link_id']) ? null : json_encode([
                        'allocation_source' => $sourceEvidence[$row['source_link_id'] ?? 0] ?? null,
                        'conversion_source' => $sourceEvidence[$basis['source_link_id'] ?? 0] ?? null,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
            $totalsQuery = new WorkVolumeCoverageTotals();
            $otherCoverage = $totalsQuery->otherCoverage($locked, $contracts->keys()->all());
            $reserved = $totalsQuery->reservations($locked, $contracts->keys()->all());
            $previousKeys = $previousRows->keyBy(fn ($row): string => $row->contract_id.':'.$row->estimate_item_id);
            foreach ($previousKeys->keys()->merge(array_keys($targetTotals))->unique() as $key) {
                $unit = $targetLimits[$key]['unit'] ?? $previousKeys->get($key)->estimate_unit_code;
                $otherQuantity = '0';
                $reservedQuantity = '0';
                foreach ($otherCoverage->get($key, collect()) as $other) {
                    if ($other->unit !== $unit) {
                        throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.coverage_unit_conversion_required'), 422);
                    }
                    $otherQuantity = $this->add($otherQuantity, (string) $other->quantity);
                }
                foreach ($reserved->get($key, collect()) as $reservation) {
                    if ($reservation->unit !== $unit) {
                        throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.coverage_unit_conversion_required'), 422);
                    }
                    $reservedQuantity = $this->add($reservedQuantity, (string) $reservation->quantity);
                }
                $total = $this->add($targetTotals[$key] ?? '0', $otherQuantity);
                if (isset($targetLimits[$key]) && $this->cmp($total, $targetLimits[$key]['quantity']) > 0) {
                    throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.coverage_exceeds_contract'), 422);
                }
                if ($this->cmp($total, $reservedQuantity) < 0) {
                    throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.coverage_below_reserved'), 422);
                }
            }
            $this->assertMappedAcceptanceCovered($locked, $normalized);
            $revision = $currentRevision + 1;
            DB::table('work_volume_coverage_revisions')->insert([
                'statement_id' => $locked->id, 'actor_id' => $actor->id, 'revision' => $revision,
                'operation_key' => $operationKey, 'operation_hash' => $operationHash, 'created_at' => now(),
            ]);
            $normalized = array_map(fn (array $row): array => [...$row, 'coverage_revision' => $revision], $normalized);
            foreach (array_chunk($normalized, 500) as $chunk) {
                WorkVolumeStatementCoverage::query()->insert($chunk);
            }
            return $this->revisionResult($locked, $revision);
        });
    }

    private function assertScope(User $actor, WorkVolumeStatement $statement, string $permission): void
    {
        $this->statements->assertScope($actor, $statement, $permission);
    }

    private function assertMappedAcceptanceCovered(WorkVolumeStatement $statement, array $rows): void
    {
        $lineKeys = $statement->lines->keyBy('id');
        $available = [];
        $physicalByContract = [];
        foreach ($rows as $row) {
            $physicalKey = $lineKeys->get($row['statement_line_id'])->line_key.':'.$row['contract_id'];
            $key = $physicalKey.':'.$row['estimate_item_id'];
            $available[$key] = $row;
            $physicalByContract[$physicalKey]['quantity'] = $this->add($physicalByContract[$physicalKey]['quantity'] ?? '0', $row['quantity']);
            $physicalByContract[$physicalKey]['unit_code'] = $row['unit_code'];
        }
        $accepted = DB::table('work_volume_accepted_allocations as allocation')
            ->join('work_volume_acceptance_mappings as mapping', 'mapping.id', '=', 'allocation.mapping_id')
            ->join('performance_act_lines as source', 'source.id', '=', 'mapping.performance_act_line_id')
            ->join('contract_performance_acts as act', 'act.id', '=', 'source.performance_act_id')
            ->where('mapping.organization_id', $statement->organization_id)->where('mapping.project_id', $statement->project_id)
            ->where('mapping.sealed', true)->whereNull('act.annulled_at')->whereIn('act.status', ActingQuantityStatus::approvedStatuses())
            ->where('allocation.line_snapshot->statement_key', $statement->statement_key)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('work_volume_acceptance_mappings as newer')
                ->whereColumn('newer.performance_act_line_id', 'mapping.performance_act_line_id')->where('newer.sealed', true)
                ->whereColumn('newer.revision', '>', 'mapping.revision'))
            ->get(['allocation.quantity', 'allocation.source_quantity', 'allocation.line_snapshot', 'source.estimate_item_id', 'source.unit', 'act.contract_id']);
        $totals = [];
        foreach ($accepted as $allocation) {
            $snapshot = json_decode($allocation->line_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $physicalKey = $snapshot['line_key'].':'.$allocation->contract_id;
            $totals[$physicalKey]['quantity'] = $this->add($totals[$physicalKey]['quantity'] ?? '0', (string) $allocation->quantity);
            $physical = $physicalByContract[$physicalKey] ?? null;
            if ($physical === null || $physical['unit_code'] !== $snapshot['unit_code']
                || $this->cmp($physical['quantity'], $totals[$physicalKey]['quantity']) < 0) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.coverage_below_reserved'), 422);
            }
            if ($allocation->estimate_item_id === null) {
                if ($allocation->unit !== $snapshot['unit_code']) {
                    throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.coverage_unit_conversion_required'), 422);
                }
                continue;
            }
            $key = $snapshot['line_key'].':'.$allocation->contract_id.':'.$allocation->estimate_item_id;
            $totals[$key]['quantity'] = $this->add($totals[$key]['quantity'] ?? '0', (string) $allocation->quantity);
            $totals[$key]['source_quantity'] = $this->add($totals[$key]['source_quantity'] ?? '0', (string) $allocation->source_quantity);
            $row = $available[$key] ?? null;
            if ($row === null || $row['unit_code'] !== $snapshot['unit_code'] || $row['estimate_unit_code'] !== $allocation->unit
                || $this->cmp($row['quantity'], $totals[$key]['quantity']) < 0
                || $this->cmp($row['estimate_quantity'], $totals[$key]['source_quantity']) < 0) {
                throw new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.coverage_below_reserved'), 422);
            }
        }
    }

    private function revisionResult(WorkVolumeStatement $statement, int $revision): array
    {
        return ['coverage_revision' => $revision, 'allocations' => WorkVolumeStatementCoverage::query()
            ->where('statement_id', $statement->id)->where('coverage_revision', $revision)->orderBy('id')->get()->toArray()];
    }

    private function cmp(string $a, string $b): int { return BigDecimal::of($a)->compareTo(BigDecimal::of($b)); }
    private function add(string $a, string $b): string { return (string) BigDecimal::of($a)->plus(BigDecimal::of($b)); }
}
