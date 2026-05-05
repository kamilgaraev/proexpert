<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Versioning;

use App\Enums\EstimatePositionItemType;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use Illuminate\Support\Collection;

class EstimateSnapshotBuilder
{
    public function __construct(
        private readonly EstimateStableKeyService $stableKeyService,
    ) {
    }

    public function build(Estimate $estimate): array
    {
        $this->stableKeyService->ensureKeys($estimate);

        $estimate = $estimate->fresh();
        $sections = $this->loadSections($estimate);
        $items = $this->loadItems($estimate);
        $sectionKeys = $sections->pluck('stable_key', 'id')->all();
        $itemsByParent = $items->groupBy(static fn (EstimateItem $item): string => (string) ($item->parent_work_id ?? 'root'));
        $rootItemsBySection = $itemsByParent
            ->get('root', collect())
            ->groupBy(static fn (EstimateItem $item): string => (string) ($item->estimate_section_id ?? 'unsectioned'));

        return [
            'schema_version' => 1,
            'estimate' => $this->estimatePayload($estimate),
            'approval' => $this->approvalPayload($estimate),
            'rates' => $this->ratesPayload($estimate),
            'totals' => $this->totalsPayload($estimate),
            'sections' => $this->buildSectionTree($sections, $rootItemsBySection, $itemsByParent, $sectionKeys),
            'unsectioned_items' => $this->buildItems(
                $rootItemsBySection->get('unsectioned', collect()),
                $itemsByParent,
                $sectionKeys
            ),
        ];
    }

    public function hash(array $snapshot): string
    {
        return hash(
            'sha256',
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    private function loadSections(Estimate $estimate): Collection
    {
        return EstimateSection::query()
            ->where('estimate_id', $estimate->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function loadItems(Estimate $estimate): Collection
    {
        return EstimateItem::query()
            ->with([
                'parentWork:id,stable_key,position_number,name,item_type,normative_rate_code',
                'measurementUnit:id,name,short_name',
            ])
            ->where('estimate_id', $estimate->id)
            ->orderBy('id')
            ->get()
            ->sort($this->compareItems(...))
            ->values();
    }

    private function estimatePayload(Estimate $estimate): array
    {
        return [
            'id' => $estimate->id,
            'organization_id' => $estimate->organization_id,
            'project_id' => $estimate->project_id,
            'contract_id' => $estimate->contract_id,
            'parent_estimate_id' => $estimate->parent_estimate_id,
            'number' => $estimate->number,
            'name' => $estimate->name,
            'description' => $estimate->description,
            'type' => $estimate->type,
            'status' => $estimate->status,
            'version' => $estimate->version,
            'estimate_date' => $estimate->estimate_date?->toDateString(),
            'base_price_date' => $estimate->base_price_date?->toDateString(),
            'calculation_method' => $estimate->calculation_method,
        ];
    }

    private function approvalPayload(Estimate $estimate): array
    {
        return [
            'approved_at' => $estimate->approved_at?->toISOString(),
            'approved_by_user_id' => $estimate->approved_by_user_id,
        ];
    }

    private function ratesPayload(Estimate $estimate): array
    {
        return [
            'vat_rate' => $this->money($estimate->vat_rate),
            'overhead_rate' => $this->money($estimate->overhead_rate),
            'profit_rate' => $this->money($estimate->profit_rate),
        ];
    }

    private function totalsPayload(Estimate $estimate): array
    {
        return [
            'total_direct_costs' => $this->money($estimate->total_direct_costs),
            'total_overhead_costs' => $this->money($estimate->total_overhead_costs),
            'total_estimated_profit' => $this->money($estimate->total_estimated_profit),
            'total_equipment_costs' => $this->money($estimate->total_equipment_costs),
            'total_amount' => $this->money($estimate->total_amount),
            'total_amount_with_vat' => $this->money($estimate->total_amount_with_vat),
            'total_base_direct_costs' => $this->money($estimate->total_base_direct_costs),
            'total_base_materials_cost' => $this->money($estimate->total_base_materials_cost),
            'total_base_machinery_cost' => $this->money($estimate->total_base_machinery_cost),
            'total_base_labor_cost' => $this->money($estimate->total_base_labor_cost),
        ];
    }

    private function buildSectionTree(
        Collection $sections,
        Collection $rootItemsBySection,
        Collection $itemsByParent,
        array $sectionKeys
    ): array {
        $sectionsByParent = $sections->groupBy(
            static fn (EstimateSection $section): string => (string) ($section->parent_section_id ?? 'root')
        );

        return $this->buildSections(
            $sectionsByParent->get('root', collect()),
            $sectionsByParent,
            $rootItemsBySection,
            $itemsByParent,
            $sectionKeys,
            null
        );
    }

    private function buildSections(
        Collection $sections,
        Collection $sectionsByParent,
        Collection $rootItemsBySection,
        Collection $itemsByParent,
        array $sectionKeys,
        ?string $parentFullNumber
    ): array {
        return $sections
            ->map(function (EstimateSection $section) use (
                $sectionsByParent,
                $rootItemsBySection,
                $itemsByParent,
                $sectionKeys,
                $parentFullNumber
            ): array {
                $fullSectionNumber = $this->sectionFullNumber($section, $parentFullNumber);

                return [
                    'id' => $section->id,
                    'stable_key' => $section->stable_key,
                    'structural_key' => $this->sectionStructuralKey($section, $sectionKeys, $fullSectionNumber),
                    'parent_section_id' => $section->parent_section_id,
                    'parent_stable_key' => $section->parent_section_id ? ($sectionKeys[$section->parent_section_id] ?? null) : null,
                    'section_number' => $section->section_number,
                    'full_section_number' => $fullSectionNumber,
                    'name' => $section->name,
                    'description' => $section->description,
                    'sort_order' => $section->sort_order,
                    'is_summary' => $section->is_summary,
                    'section_total_amount' => $this->money($section->section_total_amount),
                    'items' => $this->buildItems(
                        $rootItemsBySection->get((string) $section->id, collect()),
                        $itemsByParent,
                        $sectionKeys
                    ),
                    'children' => $this->buildSections(
                        $sectionsByParent->get((string) $section->id, collect()),
                        $sectionsByParent,
                        $rootItemsBySection,
                        $itemsByParent,
                        $sectionKeys,
                        $fullSectionNumber
                    ),
                ];
            })
            ->values()
            ->all();
    }

    private function buildItems(Collection $items, Collection $itemsByParent, array $sectionKeys): array
    {
        return $items
            ->map(fn (EstimateItem $item): array => $this->itemPayload($item, $itemsByParent, $sectionKeys))
            ->values()
            ->all();
    }

    private function itemPayload(EstimateItem $item, Collection $itemsByParent, array $sectionKeys): array
    {
        $structuralKey = $this->itemStructuralKey($item, $sectionKeys);
        $payload = [
            'id' => $item->id,
            'stable_key' => $item->stable_key,
            'structural_key' => $structuralKey,
            'resolved_key' => $this->stableKeyService->resolveItemKey([
                'stable_key' => $item->stable_key,
                'structural_key' => $structuralKey,
            ]),
            'estimate_section_id' => $item->estimate_section_id,
            'estimate_section_stable_key' => $item->estimate_section_id ? ($sectionKeys[$item->estimate_section_id] ?? null) : null,
            'parent_work_id' => $item->parent_work_id,
            'parent_work_stable_key' => $item->parentWork?->stable_key,
            'catalog_item_id' => $item->catalog_item_id,
            'normative_rate_id' => $item->normative_rate_id,
            'normative_rate_code' => $item->normative_rate_code,
            'item_type' => $this->itemTypeValue($item->item_type),
            'position_number' => $item->position_number,
            'name' => $item->name,
            'description' => $item->description,
            'measurement_unit' => $item->measurementUnit ? [
                'id' => $item->measurementUnit->id,
                'name' => $item->measurementUnit->name,
                'short_name' => $item->measurementUnit->short_name,
            ] : null,
            'quantity' => $this->quantity($item->quantity),
            'quantity_coefficient' => $this->quantity($item->quantity_coefficient),
            'quantity_total' => $this->quantity($item->quantity_total),
            'actual_quantity' => $this->quantity($item->actual_quantity),
            'unit_price' => $this->money($item->unit_price),
            'base_unit_price' => $this->money($item->base_unit_price),
            'price_index' => $this->quantity($item->price_index),
            'current_unit_price' => $this->money($item->current_unit_price),
            'price_coefficient' => $this->quantity($item->price_coefficient),
            'actual_unit_price' => $this->money($item->actual_unit_price),
            'direct_costs' => $this->money($item->direct_costs),
            'materials_cost' => $this->money($item->materials_cost),
            'machinery_cost' => $this->money($item->machinery_cost),
            'labor_cost' => $this->money($item->labor_cost),
            'equipment_cost' => $this->money($item->equipment_cost),
            'base_materials_cost' => $this->money($item->base_materials_cost),
            'base_machinery_cost' => $this->money($item->base_machinery_cost),
            'base_labor_cost' => $this->money($item->base_labor_cost),
            'overhead_amount' => $this->money($item->overhead_amount),
            'profit_amount' => $this->money($item->profit_amount),
            'total_amount' => $this->money($item->total_amount),
            'current_total_amount' => $this->money($item->current_total_amount),
            'justification' => $item->justification,
            'notes' => $item->notes,
            'is_manual' => $item->is_manual,
            'is_not_accounted' => $item->is_not_accounted,
            'procurement_status' => $item->procurement_status,
            'metadata' => $item->metadata,
            'applied_coefficients' => $item->applied_coefficients,
            'resource_calculation' => $item->resource_calculation,
            'custom_resources' => $item->custom_resources,
        ];

        $payload['children'] = $this->buildItems(
            $itemsByParent->get((string) $item->id, collect()),
            $itemsByParent,
            $sectionKeys
        );

        return $payload;
    }

    private function sectionFullNumber(EstimateSection $section, ?string $parentFullNumber): string
    {
        $sectionNumber = trim((string) $section->section_number);

        if ($parentFullNumber === null || $parentFullNumber === '') {
            return $sectionNumber;
        }

        if ($sectionNumber === '') {
            return $parentFullNumber;
        }

        return $parentFullNumber . '.' . $sectionNumber;
    }

    private function sectionStructuralKey(EstimateSection $section, array $sectionKeys, string $fullSectionNumber): string
    {
        return implode(':', [
            'section',
            $section->parent_section_id ? ($sectionKeys[$section->parent_section_id] ?? $section->parent_section_id) : 'root',
            mb_strtolower(trim($fullSectionNumber)),
            mb_strtolower(trim((string) $section->section_number)),
            mb_strtolower(trim((string) $section->name)),
        ]);
    }

    private function itemStructuralKey(EstimateItem $item, array $sectionKeys): string
    {
        return implode(':', [
            'item',
            $item->parentWork?->stable_key ?? $item->parent_work_id ?? 'root',
            $item->estimate_section_id ? ($sectionKeys[$item->estimate_section_id] ?? $item->estimate_section_id) : 'unsectioned',
            mb_strtolower(trim((string) $item->position_number)),
            mb_strtolower(trim((string) $item->normative_rate_code)),
            mb_strtolower(trim($this->itemTypeValue($item->item_type))),
            mb_strtolower(trim((string) $item->name)),
        ]);
    }

    private function itemTypeValue(mixed $itemType): string
    {
        if ($itemType instanceof EstimatePositionItemType) {
            return $itemType->value;
        }

        return (string) $itemType;
    }

    private function money(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function quantity(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 8, '.', '');
    }

    private function compareItems(EstimateItem $left, EstimateItem $right): int
    {
        return [
            $left->estimate_section_id ?? 0,
            $left->parent_work_id ?? 0,
            $this->positionSortKey($left->position_number),
            $left->id,
        ] <=> [
            $right->estimate_section_id ?? 0,
            $right->parent_work_id ?? 0,
            $this->positionSortKey($right->position_number),
            $right->id,
        ];
    }

    private function positionSortKey(?string $positionNumber): string
    {
        $parts = explode('.', trim((string) $positionNumber));

        return implode('.', array_map(
            static fn (string $part): string => ctype_digit($part) ? str_pad($part, 12, '0', STR_PAD_LEFT) : $part,
            $parts
        ));
    }
}
