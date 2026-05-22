<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Resources;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\EstimatePositionItemType;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin Estimate */
final class MobileBudgetEstimateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Estimate $estimate */
        $estimate = $this->resource;
        $availableActions = $this->availableActions($request, $estimate);

        return [
            'id' => $estimate->id,
            'organization_id' => $estimate->organization_id,
            'project_id' => $estimate->project_id,
            'project_label' => $estimate->project?->name,
            'contract_id' => $estimate->contract_id,
            'number' => $estimate->number,
            'name' => $estimate->name,
            'description' => $estimate->description,
            'type' => $estimate->type,
            'status' => $estimate->status,
            'status_label' => trans_message("budget_estimates.mobile.statuses.{$estimate->status}"),
            'version' => $estimate->version,
            'estimate_date' => $estimate->estimate_date?->toDateString(),
            'base_price_date' => $estimate->base_price_date?->toDateString(),
            'totals' => [
                'direct_costs' => (float) $estimate->total_direct_costs,
                'overhead_costs' => (float) $estimate->total_overhead_costs,
                'estimated_profit' => (float) $estimate->total_estimated_profit,
                'equipment_costs' => (float) $estimate->total_equipment_costs,
                'amount' => (float) $estimate->total_amount,
                'amount_with_vat' => (float) $estimate->total_amount_with_vat,
                'vat_rate' => (float) $estimate->vat_rate,
                'overhead_rate' => (float) $estimate->overhead_rate,
                'profit_rate' => (float) $estimate->profit_rate,
            ],
            'statistics' => [
                'sections_count' => $this->sectionsCount($estimate),
                'items_count' => $this->itemsCount($estimate),
            ],
            'approval_summary' => [
                'status' => $estimate->status,
                'status_label' => trans_message("budget_estimates.mobile.statuses.{$estimate->status}"),
                'approved_by_user_id' => $estimate->approved_by_user_id,
                'approved_by_label' => $estimate->approvedBy?->name,
                'approved_at' => $estimate->approved_at?->toIso8601String(),
                'available_actions' => $availableActions,
            ],
            'available_actions' => $availableActions,
            'line_groups' => $this->lineGroups($estimate),
            'unsectioned_items' => $this->unsectionedItems($estimate),
            'created_at' => $estimate->created_at?->toIso8601String(),
            'updated_at' => $estimate->updated_at?->toIso8601String(),
        ];
    }

    private function availableActions(Request $request, Estimate $estimate): array
    {
        $user = $request->user();
        if (!$user || $estimate->status !== 'in_review') {
            return [];
        }

        $canApprove = app(AuthorizationService::class)->can(
            $user,
            'budget-estimates.approve',
            ['organization_id' => (int) $estimate->organization_id]
        );

        return $canApprove ? ['approve', 'request_changes'] : [];
    }

    private function sectionsCount(Estimate $estimate): int
    {
        if ($estimate->relationLoaded('sections')) {
            return $estimate->sections->count();
        }

        if (isset($estimate->sections_count)) {
            return (int) $estimate->sections_count;
        }

        $statistics = is_array($estimate->statistics) ? $estimate->statistics : [];

        return (int) ($statistics['sections_count'] ?? 0);
    }

    private function itemsCount(Estimate $estimate): int
    {
        if ($estimate->relationLoaded('items')) {
            return $estimate->items->count();
        }

        if (isset($estimate->items_count)) {
            return (int) $estimate->items_count;
        }

        $statistics = is_array($estimate->statistics) ? $estimate->statistics : [];

        return (int) ($statistics['items_count'] ?? 0);
    }

    private function lineGroups(Estimate $estimate): array
    {
        if (!$estimate->relationLoaded('sections')) {
            return [];
        }

        return $estimate->sections
            ->map(fn (EstimateSection $section): array => [
                'id' => $section->id,
                'estimate_id' => $section->estimate_id,
                'parent_section_id' => $section->parent_section_id,
                'section_number' => $section->full_section_number ?? $section->section_number,
                'name' => $section->name,
                'description' => $section->description,
                'sort_order' => (int) $section->sort_order,
                'is_summary' => (bool) $section->is_summary,
                'total_amount' => (float) $section->section_total_amount,
                'items' => $this->itemsPayload($section->relationLoaded('items') ? $section->items : collect()),
            ])
            ->values()
            ->all();
    }

    private function unsectionedItems(Estimate $estimate): array
    {
        if (!$estimate->relationLoaded('items')) {
            return [];
        }

        return $this->itemsPayload(
            $estimate->items->filter(fn (EstimateItem $item): bool => $item->estimate_section_id === null)
        );
    }

    private function itemsPayload(Collection $items): array
    {
        return $items
            ->filter(fn (EstimateItem $item): bool => $item->parent_work_id === null)
            ->map(fn (EstimateItem $item): array => [
                'id' => $item->id,
                'estimate_id' => $item->estimate_id,
                'estimate_section_id' => $item->estimate_section_id,
                'position_number' => $item->position_number,
                'name' => $item->name,
                'item_type' => $this->itemType($item),
                'measurement_unit_label' => $item->measurementUnit?->short_name,
                'quantity' => $item->quantity !== null ? (float) $item->quantity : null,
                'quantity_total' => $item->quantity_total !== null ? (float) $item->quantity_total : null,
                'unit_price' => $item->unit_price !== null ? (float) $item->unit_price : null,
                'current_unit_price' => $item->current_unit_price !== null ? (float) $item->current_unit_price : null,
                'total_amount' => $item->total_amount !== null ? (float) $item->total_amount : null,
                'current_total_amount' => $item->current_total_amount !== null ? (float) $item->current_total_amount : null,
                'procurement_status' => $item->procurement_status,
            ])
            ->values()
            ->all();
    }

    private function itemType(EstimateItem $item): ?string
    {
        if ($item->item_type instanceof EstimatePositionItemType) {
            return $item->item_type->value;
        }

        return $item->item_type !== null ? (string) $item->item_type : null;
    }
}
