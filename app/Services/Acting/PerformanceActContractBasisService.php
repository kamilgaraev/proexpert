<?php

declare(strict_types=1);

namespace App\Services\Acting;

use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceTax;
use App\Exceptions\BusinessLogicException;
use App\Models\Contract;
use App\Models\EstimateFinanceAllocation;
use App\Models\EstimateItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class PerformanceActContractBasisService
{
    public function resolve(EstimateItem $item, Contract $contract, ?string $allocationKey = null): ?array
    {
        $estimate = $item->estimate;
        if ($estimate === null || (int) $estimate->organization_id !== (int) $contract->organization_id
            || ! in_array((int) $estimate->project_id, array_map('intval', $contract->getProjectIds()), true)) {
            throw new BusinessLogicException(trans_message('act_reports.work_not_available_for_acting'), 422);
        }

        $allocations = ($item->relationLoaded('financeAllocations') ? $item->financeAllocations
            : EstimateFinanceAllocation::query()->where('estimate_item_id', $item->id)->where('contract_id', $contract->id)->get())
            ->filter(static fn (EstimateFinanceAllocation $allocation): bool => (int) $allocation->organization_id === (int) $contract->organization_id
                && (int) $allocation->estimate_id === (int) $estimate->id && (int) $allocation->contract_id === (int) $contract->id
                && $allocation->resource_id === null)->sortBy('id')->values();
        if ($allocations->isEmpty() && $allocationKey === null) {
            return null;
        }
        $selected = $allocationKey === null ? $allocations : $allocations->where('key', $allocationKey);
        if ($selected->count() !== 1) {
            throw new BusinessLogicException(trans_message('act_reports.contract_conditions_selection_required'), 422);
        }
        $allocation = $selected->first();
        $mode = EstimateFinanceTax::mode($allocation->toArray());
        if (! $allocation->composition_confirmed || $mode === 'unknown' || $mode === null
            || ($mode !== 'none' && $allocation->vat_rate === null)
            || $allocation->amount_without_vat === null || $allocation->amount_with_vat === null
            || strtoupper((string) $allocation->currency) !== strtoupper((string) $contract->currency)
            || ! BigDecimal::of((string) $allocation->quantity)->isGreaterThan(0)) {
            throw new BusinessLogicException(trans_message('act_reports.contract_conditions_required'), 422);
        }
        $conditions = $allocation->condition_basis ?? $allocation->toArray();
        $quantity = BigDecimal::of((string) $conditions['quantity']);
        if (! $quantity->isGreaterThan(0)) {
            throw new BusinessLogicException(trans_message('act_reports.work_not_available_for_acting'), 422);
        }
        $base = BigDecimal::of((string) $conditions['amount_without_vat'])->dividedBy($quantity, 8, RoundingMode::HalfUp);
        $gross = BigDecimal::of((string) $conditions['amount_with_vat'])->dividedBy($quantity, 2, RoundingMode::HalfUp);
        $rate = $mode === 'none' ? '0.00' : (string) BigDecimal::of((string) $allocation->vat_rate)->toScale(2, RoundingMode::HalfUp);

        return [
            'estimate_version_id' => null,
            'base_unit_price' => (string) $base,
            'unit_price' => (string) $gross,
            'vat_rate' => $rate,
            'snapshot' => [
                'basis_type' => 'contract_conditions',
                'allocation_key' => $allocation->key,
                'condition_version' => $allocation->condition_version,
                'contract_id' => (int) $contract->id,
                'estimate_id' => (int) $estimate->id,
                'estimate_item_id' => (int) $item->id,
                'base_unit_price' => (string) $base,
                'unit_price_with_vat' => (string) $gross,
                'vat_rate' => $rate,
                'vat_mode' => $mode,
                'currency' => $allocation->currency,
                'conditions' => $allocation->toArray(),
            ],
        ];
    }
}
