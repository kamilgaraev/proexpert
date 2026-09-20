<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Services;

use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceExecution;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceQuery;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\Estimate;
use App\Models\PerformanceActLine;
use App\Models\User;
use App\Services\Acting\ActingQuantityStatus;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

final class ContractEstimateOperationalProgress
{
    public function __construct(private readonly AuthorizationService $authorization, private readonly UserProjectAccessService $projects,
        private readonly EstimateFinanceExecution $execution, private readonly EstimateFinanceQuery $financeQuery) {}

    public function prepare(Contract $contract, Collection $links, ?User $actor): void
    {
        if ($actor !== null && (int) $actor->current_organization_id !== (int) $contract->organization_id) {
            throw new AuthorizationException;
        }
        if ($actor !== null && ! $this->projects->queryAccessibleProjects($actor, (int) $contract->organization_id)->whereKey($contract->project_id)->exists()) {
            throw new AuthorizationException;
        }
        if ($links->contains(fn ($link): bool => (int) $link->contract_id !== (int) $contract->id)) {
            throw new AuthorizationException;
        }
        $context = ['context_type' => 'project', 'project_id' => (int) $contract->project_id,
            'organization_id' => (int) $contract->organization_id];
        $can = fn (string $permission): bool => $actor !== null && $this->authorization->can($actor, $permission, $context);
        $canViewWorks = $can('contracts.completed_works.view');
        $canViewActs = $can('contracts.performance_acts.view');
        $canEdit = $can('contracts.edit');
        $canCreateAct = $can('contracts.performance_acts.create');
        $itemIds = $links->pluck('estimate_item_id')->unique()->values()->all();
        $actual = [];
        $acting = [];
        $approved = [];

        if ($itemIds !== [] && $canViewWorks) {
            $actual = CompletedWork::query()->effectiveForSchedule()
                ->where('organization_id', $contract->organization_id)
                ->where('project_id', $contract->project_id)
                ->where('contract_id', $contract->id)
                ->whereIn('estimate_item_id', $itemIds)
                ->selectRaw('estimate_item_id, SUM(COALESCE(completed_quantity, quantity, 0)) AS actual_quantity')
                ->groupBy('estimate_item_id')->pluck('actual_quantity', 'estimate_item_id')->all();
        }
        if ($itemIds !== [] && $canViewActs) {
            $lines = PerformanceActLine::query()->with('performanceAct')
                ->whereIn('estimate_item_id', $itemIds)
                ->whereHas('performanceAct', function ($query) use ($contract): void {
                    $query->where('contract_id', $contract->id)->where('project_id', $contract->project_id);
                })->get();
            foreach ($lines as $line) {
                if (ActingQuantityStatus::isReleased($line->performanceAct) || ActingQuantityStatus::isApproved($line->performanceAct)) {
                    continue;
                }
                $acting[$line->estimate_item_id]['reserved_quantity'] = FinanceDecimal::add($acting[$line->estimate_item_id]['reserved_quantity'] ?? '0', (string) $line->quantity);
            }
            $estimateIds = $links->pluck('estimate_id')->unique()->values();
            $estimates = Estimate::query()->where('organization_id', $contract->organization_id)->where('project_id', $contract->project_id)
                ->whereIn('id', $estimateIds)->get();
            if ($estimates->count() !== $estimateIds->count()) {
                throw new AuthorizationException;
            }
            $allowedItems = array_fill_keys($itemIds, true);
            $contracts = $estimates->isEmpty() ? [] : array_values(array_filter($this->financeQuery->contracts($estimates->first()),
                static fn (array $row): bool => (int) $row['id'] === (int) $contract->id));
            foreach ($estimates as $estimate) {
                foreach ($this->execution->report($estimate, $contracts)['rows'] as $source) {
                    if (! isset($allowedItems[$source['item_id']]) || ($source['resource_id'] ?? null) !== null) {
                        continue;
                    }
                    $itemId = (int) $source['item_id'];
                    $previous = array_key_exists($itemId, $approved) ? $approved[$itemId] : '0';
                    $approved[$itemId] = $previous === null || $source['quantity'] === null ? null : FinanceDecimal::add($previous, $source['quantity']);
                }
            }
        }

        foreach ($links as $link) {
            $link->setRelation('operationalProgress', [
                'can_view_works' => $canViewWorks,
                'can_view_acts' => $canViewActs,
                'can_edit' => $canEdit,
                'can_create_act' => $canCreateAct,
                'actual_quantity' => $canViewWorks ? round((float) ($actual[$link->estimate_item_id] ?? 0), 4) : null,
                'reserved_quantity' => $canViewActs ? round((float) ($acting[$link->estimate_item_id]['reserved_quantity'] ?? '0'), 4) : null,
                'approved_acted_quantity' => ! $canViewActs || (array_key_exists($link->estimate_item_id, $approved) && $approved[$link->estimate_item_id] === null)
                    ? null : round((float) ($approved[$link->estimate_item_id] ?? '0'), 4),
            ]);
        }
    }
}
