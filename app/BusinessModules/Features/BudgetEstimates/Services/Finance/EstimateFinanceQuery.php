<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Estimate;
use App\Models\EstimateFinanceAllocation;
use App\Models\EstimateItem;

final class EstimateFinanceQuery
{
    public function targets(Estimate $estimate): array
    {
        $targets = [];
        $items = EstimateItem::query()->where('estimate_id', $estimate->id)
            ->with(['resources.measurementUnit', 'measurementUnit'])->orderBy('id')->get();
        $childParents = $items->whereNotNull('parent_work_id')->pluck('parent_work_id')->flip();
        foreach ($items as $item) {
            $key = 'i:'.$item->id;
            $targets[$key] = [
                'key' => $key, 'item_id' => (int) $item->id, 'resource_id' => null,
                'parent_key' => $item->parent_work_id ? 'i:'.$item->parent_work_id : null,
                'section_id' => $item->estimate_section_id, 'name' => $item->name,
                'position_number' => (string) $item->position_number,
                'unit' => $item->measurementUnit?->short_name, 'unit_id' => $item->measurement_unit_id,
                'quantity' => (string) ($item->quantity_total ?? $item->quantity ?? '0'),
                'estimate_amount' => (string) ($item->total_amount ?? '0'),
                'estimate_amount_with_vat' => $estimate->vat_rate === null ? null : FinanceDecimal::multiply((string) ($item->total_amount ?? '0'), FinanceDecimal::add('1', FinanceDecimal::divide((string) $estimate->vat_rate, '100', 8))),
                'excluded' => (bool) $item->is_not_accounted,
                'pending_resources' => $item->resources->isEmpty() ? $this->pendingResources($item) : [],
            ];
            foreach ($item->resources as $resource) {
                $resourceKey = 'r:'.$resource->id;
                $targets[$resourceKey] = [
                    'key' => $resourceKey, 'item_id' => (int) $item->id, 'resource_id' => (int) $resource->id,
                    'parent_key' => $key, 'section_id' => $item->estimate_section_id,
                    'name' => $resource->name, 'position_number' => '', 'unit' => $resource->measurementUnit?->short_name ?? $resource->finance_unit_label,
                    'unit_id' => $resource->measurement_unit_id,
                    'represented_by_item_id' => $resource->represented_by_item_id,
                    'representation' => $resource->finance_representation,
                    'representation_needs_review' => ($childParents->has($item->id) && $resource->finance_representation === 'unreviewed')
                        || ($resource->represented_by_item_id && ! $items->contains('id', $resource->represented_by_item_id)),
                    'source_changed' => $resource->finance_source_hash !== null && $resource->finance_source_hash !== hash('sha256', json_encode($this->pendingResources($item), JSON_THROW_ON_ERROR)),
                    'quantity' => (string) ($resource->total_quantity ?? '0'),
                    'estimate_amount' => (string) ($resource->total_amount ?? '0'), 'excluded' => (bool) $item->is_not_accounted,
                    'estimate_amount_with_vat' => $estimate->vat_rate === null ? null : FinanceDecimal::multiply((string) ($resource->total_amount ?? '0'), FinanceDecimal::add('1', FinanceDecimal::divide((string) $estimate->vat_rate, '100', 8))),
                ];
            }
        }
        foreach ($targets as $key => $target) {
            $parent = $target['parent_key'];
            $seen = [];
            while ($parent && isset($targets[$parent]) && ! isset($seen[$parent])) {
                $seen[$parent] = true;
                if ($targets[$parent]['excluded']) {
                    $targets[$key]['excluded'] = true;
                    break;
                }
                $parent = $targets[$parent]['parent_key'];
            }
        }

        return $targets;
    }

    public function allocations(Estimate $estimate): array
    {
        $models = EstimateFinanceAllocation::query()->where('estimate_id', $estimate->id)->with('contract')->orderBy('id')->get();
        $rows = [];
        foreach ($models as $model) {
            $row = $model->attributesToArray();
            if ($model->source === 'contract' && (! $model->contract || $this->side($model->contract) !== $model->side
                || ($model->contract->currency ?: 'RUB') !== $model->currency
                || (int) $model->contract->organization_id !== (int) $estimate->organization_id
                || (int) $model->contract->project_id !== (int) $estimate->project_id)) {
                $row['side'] = 'unknown';
                $row['composition_confirmed'] = false;
            }
            $rows[] = $row;
        }
        foreach ($rows as &$row) {
            $row['target_key'] = $row['resource_id'] ? 'r:'.$row['resource_id'] : 'i:'.$row['estimate_item_id'];
            $row['legacy'] = false;
        }
        unset($row);
        $legacy = ContractEstimateItem::query()->where('estimate_id', $estimate->id)->where('finance_managed', false)->with('contract')->get();
        foreach ($legacy as $link) {
            $rows[] = [
                'key' => 'legacy:'.$link->id, 'target_key' => 'i:'.$link->estimate_item_id,
                'estimate_item_id' => (int) $link->estimate_item_id, 'resource_id' => null,
                'contract_id' => (int) $link->contract_id, 'contract_estimate_item_id' => (int) $link->id,
                'side' => $link->contract ? $this->side($link->contract) : 'unknown', 'source' => 'contract',
                'currency' => $link->contract?->currency ?? 'RUB', 'quantity' => (string) ($link->quantity ?? '0'),
                'unit_price' => null, 'amount_without_vat' => $link->amount_without_vat,
                'amount_with_vat' => null, 'legacy_amount' => $link->amount,
                'vat_rate' => null, 'price_basis' => 'unknown', 'method' => 'legacy',
                'composition_confirmed' => false, 'estimate_snapshot' => null, 'notes' => $link->notes, 'legacy' => true,
            ];
        }

        return $rows;
    }

    public function contracts(Estimate $estimate): array
    {
        return Contract::query()->where('organization_id', $estimate->organization_id)
            ->where('project_id', $estimate->project_id)->with(['contractor', 'supplier'])->orderBy('number')->get()
            ->map(fn (Contract $contract): array => [
                'id' => (int) $contract->id, 'number' => $contract->number, 'date' => $contract->date?->format('Y-m-d'),
                'name' => $contract->contractor?->name ?? $contract->supplier?->name,
                'side' => $this->side($contract), 'side_type' => $contract->contract_side_type?->value,
                'currency' => $contract->currency ?: 'RUB', 'status' => $contract->status?->value,
            ])->all();
    }

    public function side(Contract $contract): string
    {
        if ($contract->requires_contract_side_review) {
            return 'unknown';
        }

        return match ($contract->contract_side_type?->value) {
            'customer_to_general_contractor' => 'revenue',
            'general_contractor_to_contractor', 'general_contractor_to_supplier',
            'contractor_to_subcontractor', 'contractor_to_supplier', 'subcontractor_to_supplier' => 'cost',
            default => 'unknown',
        };
    }

    public function pendingResources(EstimateItem $item): array
    {
        $definitions = $item->resource_calculation ?: $item->custom_resources ?: [];
        $resources = [];
        foreach ($definitions as $definition) {
            if (! is_array($definition) || ! isset($definition['name'])) {
                continue;
            }
            $resources[] = [
                'name' => (string) $definition['name'], 'unit' => is_string($definition['unit'] ?? null) ? $definition['unit'] : null,
                'quantity' => isset($definition['total_consumption']) || isset($definition['total_quantity']) || isset($definition['quantity']) ? (string) ($definition['total_consumption'] ?? $definition['total_quantity'] ?? $definition['quantity']) : null,
                'amount' => isset($definition['total_cost']) || isset($definition['total_amount']) ? (string) ($definition['total_cost'] ?? $definition['total_amount']) : null,
                'unit_price' => isset($definition['unit_price']) ? (string) $definition['unit_price'] : null,
                'quantity_per_unit' => isset($definition['consumption_per_unit']) || isset($definition['quantity_per_unit']) ? (string) ($definition['consumption_per_unit'] ?? $definition['quantity_per_unit']) : null,
                'type' => (string) ($definition['type'] ?? $definition['resource_type'] ?? 'other'),
            ];
        }

        return $resources;
    }
}
