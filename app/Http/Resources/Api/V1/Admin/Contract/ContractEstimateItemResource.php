<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Contract;

use App\Models\ContractEstimateItem;
use Illuminate\Http\Resources\Json\JsonResource;

use function trans_message;

/** @mixin ContractEstimateItem */
class ContractEstimateItemResource extends JsonResource
{
    public function toArray($request): array
    {
        $item = $this->estimateItem;
        $section = $item?->section;
        $plannedQuantity = $item?->resolvePlannedQuantity($this->resource) ?? 0.0;
        $progress = $this->resource->relationLoaded('operationalProgress') ? $this->resource->getRelation('operationalProgress') : [];
        $actualVolume = $progress['actual_quantity'] ?? null;
        $completionPercentage = $actualVolume === null ? null : ($plannedQuantity > 0 ? min(100, $actualVolume / $plannedQuantity * 100) : 0.0);
        $actingQuantities = [
            'reserved_quantity' => $progress['reserved_quantity'] ?? null,
            'approved_acted_quantity' => $progress['approved_acted_quantity'] ?? null,
        ];
        $availableQuantity = $actualVolume === null || $actingQuantities['reserved_quantity'] === null || $actingQuantities['approved_acted_quantity'] === null ? null : round(max(
            0,
            $actualVolume - $actingQuantities['reserved_quantity'] - $actingQuantities['approved_acted_quantity']
        ), 4);
        $blockers = $actualVolume === null ? [] : $this->buildBlockers($plannedQuantity, $actualVolume);
        $availableActions = array_values(array_filter($this->buildAvailableActions($blockers, $availableQuantity ?? 0), static fn (string $action): bool => match ($action) {
            'view_completed_works' => $progress['can_view_works'] ?? false,
            'fix_planned_quantity' => $progress['can_edit'] ?? false,
            'create_act' => $progress['can_create_act'] ?? false,
            default => false,
        }));

        return [
            'id'                => $this->id,
            'contract_id'       => $this->contract_id,
            'estimate_id'       => $this->estimate_id,
            'estimate_item_id'  => $this->estimate_item_id,
            'quantity'          => (float) $this->quantity,
            'amount'            => $this->amount === null ? null : (float) $this->amount,
            'contract_unit_price' => $this->amount === null || (float) $this->quantity <= 0 ? null
                : (float) \App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal::divide((string) $this->amount, (string) $this->quantity),
            'notes'             => $this->notes,
            'item' => $item ? [
                'id'              => $item->id,
                'position_number' => $item->position_number,
                'name'            => $item->name,
                'item_type'       => $item->item_type instanceof \App\Enums\EstimatePositionItemType
                    ? $item->item_type->value
                    : $item->item_type,
                'quantity_total'  => (float) $item->quantity_total,
                'unit_price'      => (float) $item->unit_price,
                'total_amount'    => (float) $item->total_amount,
                'parent_work_id'  => $item->parent_work_id,
                'section_id'      => $item->estimate_section_id,
                'section' => $section ? [
                    'id' => $section->id,
                    'name' => $section->name,
                    'section_number' => $section->full_section_number ?? $section->section_number,
                ] : null,
                'measurement_unit' => $item->relationLoaded('measurementUnit') && $item->measurementUnit
                    ? ['id' => $item->measurementUnit->id, 'short_name' => $item->measurementUnit->short_name]
                    : null,
                'children_count' => $item->relationLoaded('childItems') ? $item->childItems->count() : 0,
                'contracts_count' => $item->getAttribute('contract_links_count'),
                'planned_quantity' => round((float) $plannedQuantity, 4),
                'actual_quantity' => $actualVolume,
                'actual_volume' => $actualVolume,
                'completion_percentage' => $completionPercentage === null ? null : round($completionPercentage, 2),
                'fact_progress_percent' => $completionPercentage === null ? null : round($completionPercentage, 2),
                'can_view_works' => $progress['can_view_works'] ?? false,
                'can_view_acts' => $progress['can_view_acts'] ?? false,
                'reserved_quantity' => $actingQuantities['reserved_quantity'],
                'acted_quantity' => $actingQuantities['approved_acted_quantity'],
                'available_quantity' => $availableQuantity,
                'acted_progress_percent' => $actingQuantities['approved_acted_quantity'] === null ? null : ($plannedQuantity > 0
                    ? round(min(100, ($actingQuantities['approved_acted_quantity'] / $plannedQuantity) * 100), 2)
                    : 0.0),
                'workflow_state' => $actualVolume === null || $availableQuantity === null ? 'unavailable' : ($blockers === [] ? 'ready' : 'blocked'),
                'blockers' => $blockers,
                'available_actions' => $availableActions,
            ] : null,
        ];
    }

    private function buildBlockers(float $plannedQuantity, float $actualVolume): array
    {
        if ($plannedQuantity > 0 || $actualVolume <= 0) {
            return [];
        }

        return [[
            'code' => 'missing_planned_quantity',
            'message' => trans_message('workflow.blockers.missing_planned_quantity'),
            'target' => 'over_coverage',
        ]];
    }

    private function buildAvailableActions(array $blockers, float $availableQuantity): array
    {
        $actions = ['view_completed_works'];

        if ($blockers !== []) {
            $actions[] = 'fix_planned_quantity';
            return $actions;
        }

        if ($availableQuantity > 0) {
            $actions[] = 'create_act';
        }

        return $actions;
    }
}
