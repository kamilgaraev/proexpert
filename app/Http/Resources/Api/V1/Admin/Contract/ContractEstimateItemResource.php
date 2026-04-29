<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Contract;

use App\Models\ContractEstimateItem;
use App\Models\PerformanceActLine;
use App\Services\Acting\ActingQuantityStatus;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

use function trans_message;

/** @mixin ContractEstimateItem */
class ContractEstimateItemResource extends JsonResource
{
    public function toArray($request): array
    {
        $item = $this->estimateItem;
        $section = $item?->section;
        $plannedQuantity = $item?->resolvePlannedQuantity($this->resource) ?? 0.0;
        $actualVolume = $item?->getActualVolume((int) $this->contract_id) ?? 0.0;
        $completionPercentage = $item?->getCompletionPercentageForPlannedQuantity(
            $plannedQuantity,
            (int) $this->contract_id,
        ) ?? 0.0;
        $actingQuantities = $item ? $this->resolveActingQuantities((int) $item->id, (int) $this->contract_id) : [
            'reserved_quantity' => 0.0,
            'approved_acted_quantity' => 0.0,
        ];
        $availableQuantity = round(max(
            0,
            $actualVolume - $actingQuantities['reserved_quantity'] - $actingQuantities['approved_acted_quantity']
        ), 4);
        $blockers = $this->buildBlockers($plannedQuantity, $actualVolume);
        $availableActions = $this->buildAvailableActions($blockers, $availableQuantity);

        return [
            'id'                => $this->id,
            'contract_id'       => $this->contract_id,
            'estimate_id'       => $this->estimate_id,
            'estimate_item_id'  => $this->estimate_item_id,
            'quantity'          => (float) $this->quantity,
            'amount'            => (float) $this->amount,
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
                'contracts_count' => $item->contractLinks()->count(),
                'planned_quantity' => round((float) $plannedQuantity, 4),
                'actual_quantity' => round((float) $actualVolume, 4),
                'actual_volume' => round((float) $actualVolume, 4),
                'completion_percentage' => round((float) $completionPercentage, 2),
                'fact_progress_percent' => round((float) $completionPercentage, 2),
                'reserved_quantity' => $actingQuantities['reserved_quantity'],
                'acted_quantity' => $actingQuantities['approved_acted_quantity'],
                'available_quantity' => $availableQuantity,
                'acted_progress_percent' => $plannedQuantity > 0
                    ? round(min(100, ($actingQuantities['approved_acted_quantity'] / $plannedQuantity) * 100), 2)
                    : 0.0,
                'workflow_state' => $blockers === [] ? 'ready' : 'blocked',
                'blockers' => $blockers,
                'available_actions' => $availableActions,
            ] : null,
        ];
    }

    private function resolveActingQuantities(int $estimateItemId, int $contractId): array
    {
        /** @var Collection<int, PerformanceActLine> $lines */
        $lines = PerformanceActLine::query()
            ->with('performanceAct')
            ->where('estimate_item_id', $estimateItemId)
            ->whereHas('performanceAct', function ($query) use ($contractId): void {
                $query->where('contract_id', $contractId);
            })
            ->get();

        $reserved = 0.0;
        $approved = 0.0;

        foreach ($lines as $line) {
            $act = $line->performanceAct;

            if (ActingQuantityStatus::isReleased($act)) {
                continue;
            }

            if (ActingQuantityStatus::isApproved($act)) {
                $approved += (float) $line->quantity;
                continue;
            }

            $reserved += (float) $line->quantity;
        }

        return [
            'reserved_quantity' => round($reserved, 4),
            'approved_acted_quantity' => round($approved, 4),
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
