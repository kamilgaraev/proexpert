<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Estimate;
use App\Models\EstimateFinanceAllocation;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;

final class EstimateFinanceAccess
{
    public function __construct(private readonly AuthorizationService $authorization, private readonly UserProjectAccessService $projects) {}

    public function project(User $actor, int $projectId, bool $edit = false): void
    {
        if (! $this->projects->queryAccessibleProjects($actor, (int) $actor->current_organization_id)->whereKey($projectId)->exists()
            || ! $this->can($actor, $projectId, $edit)) {
            throw new AuthorizationException;
        }
    }

    public function estimate(User $actor, int $projectId, int $estimateId, bool $edit = false): Estimate
    {
        $this->project($actor, $projectId, $edit);

        return Estimate::query()->where('organization_id', $actor->current_organization_id)
            ->where('project_id', $projectId)->findOrFail($estimateId);
    }

    public function can(User $actor, int $projectId, bool $edit): bool
    {
        return $this->authorization->can($actor, 'budget-estimates.finance.'.($edit ? 'edit' : 'view'), [
            'context_type' => 'project', 'project_id' => $projectId, 'organization_id' => (int) $actor->current_organization_id,
        ]);
    }

    public function editContracts(User $actor, Estimate $estimate, array $targetKeys, array $contractIds): void
    {
        $targets = array_fill_keys($targetKeys, true);
        $existing = EstimateFinanceAllocation::query()->where('estimate_id', $estimate->id)
            ->whereNotNull('contract_id')->get(['contract_id', 'estimate_item_id', 'resource_id']);
        foreach ($existing as $allocation) {
            $key = $allocation->resource_id ? 'r:'.$allocation->resource_id : 'i:'.$allocation->estimate_item_id;
            if (isset($targets[$key])) {
                $contractIds[] = $allocation->contract_id;
            }
        }
        $itemIds = array_map(static fn (string $key): int => (int) substr($key, 2),
            array_values(array_filter($targetKeys, static fn (string $key): bool => str_starts_with($key, 'i:'))));
        $contractIds = array_values(array_unique(array_merge($contractIds,
            ContractEstimateItem::query()->where('estimate_id', $estimate->id)->whereIn('estimate_item_id', $itemIds)
                ->pluck('contract_id')->all())));
        if ($contractIds === []) {
            return;
        }
        if ((int) $actor->current_organization_id !== (int) $estimate->organization_id
            || Contract::query()->where('organization_id', $estimate->organization_id)->where('project_id', $estimate->project_id)
                ->whereIn('id', $contractIds)->count() !== count($contractIds)
            || ! $this->authorization->can($actor, 'contracts.edit', [
                'context_type' => 'project', 'project_id' => (int) $estimate->project_id,
                'organization_id' => (int) $estimate->organization_id,
            ])) {
            throw new AuthorizationException;
        }
    }

    public function canViewExecution(User $actor, int $projectId): bool
    {
        $context = [
            'context_type' => 'project', 'project_id' => $projectId,
            'organization_id' => (int) $actor->current_organization_id,
        ];

        return $this->authorization->can($actor, 'contracts.performance_acts.view', $context)
            || $this->authorization->can($actor, 'act_reports.view', $context);
    }
}
