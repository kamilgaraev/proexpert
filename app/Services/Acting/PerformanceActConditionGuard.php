<?php

declare(strict_types=1);

namespace App\Services\Acting;

use App\Exceptions\BusinessLogicException;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\Estimate;
use App\Models\EstimateFinanceAllocation;

final class PerformanceActConditionGuard
{
    public function assertCurrent(ContractPerformanceAct $act, Contract $contract): void
    {
        $lines = $act->lines()->whereNotNull('estimate_item_id')->get();
        if ($lines->isEmpty()) {
            return;
        }
        $allocations = EstimateFinanceAllocation::query()->where('organization_id', $contract->organization_id)
            ->where('contract_id', $contract->id)->whereIn('estimate_item_id', $lines->pluck('estimate_item_id'))
            ->whereIn('estimate_id', Estimate::query()->select('id')->where('organization_id', $contract->organization_id)
                ->where('project_id', $act->project_id))
            ->whereNull('resource_id')->get()->keyBy('key');
        foreach ($lines as $line) {
            $snapshot = $line->basis_snapshot ?? [];
            $managed = ($snapshot['basis_type'] ?? null) === 'contract_conditions';
            if (! $managed) {
                if ($allocations->contains('estimate_item_id', $line->estimate_item_id)) {
                    $this->conflict();
                }
                continue;
            }
            $allocation = $allocations->get($snapshot['allocation_key'] ?? '');
            if ($allocation === null || (int) $allocation->estimate_item_id !== (int) $line->estimate_item_id
                || (int) $allocation->condition_version !== (int) ($snapshot['condition_version'] ?? 0)
                || (int) $allocation->estimate_id !== (int) ($snapshot['estimate_id'] ?? 0)
                || (int) $contract->id !== (int) ($snapshot['contract_id'] ?? 0)
                || (int) $act->contract_id !== (int) $contract->id) {
                $this->conflict();
            }
        }
    }

    private function conflict(): never
    {
        throw new BusinessLogicException(trans_message('act_reports.contract_conditions_changed'), 409);
    }
}
