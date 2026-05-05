<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Versioning;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateVersioningService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\EstimateVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class EstimateVersionRestoreService
{
    public function __construct(
        private readonly EstimateVersioningService $versioningService,
    ) {
    }

    public function restore(Estimate $estimate, EstimateVersion $version, int $actorId): Estimate
    {
        if ((int) $version->estimate_id !== (int) $estimate->id) {
            throw new InvalidArgumentException('Версия не принадлежит выбранной смете');
        }

        return DB::transaction(function () use ($estimate, $version, $actorId): Estimate {
            $lockedEstimate = Estimate::query()
                ->whereKey($estimate->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->versioningService->createSnapshot(
                estimate: $lockedEstimate,
                actorId: $actorId,
                label: 'Перед восстановлением из версии ' . $version->version_number,
                snapshotType: 'pre_restore'
            );

            $snapshot = $version->snapshot ?? [];

            $this->applyEstimateSnapshot($lockedEstimate, $snapshot);
            $this->replaceStructure($lockedEstimate, $snapshot);

            $restoredEstimate = $lockedEstimate->fresh();

            $this->versioningService->createSnapshot(
                estimate: $restoredEstimate,
                actorId: $actorId,
                label: 'Восстановление из версии ' . $version->version_number,
                snapshotType: 'restore'
            );

            return $this->loadRestoredEstimate($lockedEstimate);
        });
    }

    private function applyEstimateSnapshot(Estimate $estimate, array $snapshot): void
    {
        $estimatePayload = $snapshot['estimate'] ?? [];
        $totalsPayload = $snapshot['totals'] ?? [];
        $ratesPayload = $snapshot['rates'] ?? [];

        $estimate->forceFill(array_merge(
            $this->only($estimatePayload, [
                'project_id',
                'contract_id',
                'parent_estimate_id',
                'number',
                'name',
                'description',
                'type',
                'version',
                'estimate_date',
                'base_price_date',
                'calculation_method',
            ]),
            $this->only($totalsPayload, [
                'total_direct_costs',
                'total_overhead_costs',
                'total_estimated_profit',
                'total_equipment_costs',
                'total_amount',
                'total_amount_with_vat',
                'total_base_direct_costs',
                'total_base_materials_cost',
                'total_base_machinery_cost',
                'total_base_labor_cost',
            ]),
            $this->only($ratesPayload, [
                'vat_rate',
                'overhead_rate',
                'profit_rate',
            ]),
            [
                'status' => 'draft',
                'approved_at' => null,
                'approved_by_user_id' => null,
            ]
        ))->save();
    }

    private function replaceStructure(Estimate $estimate, array $snapshot): void
    {
        $existingSectionsByStableKey = EstimateSection::query()
            ->where('estimate_id', $estimate->id)
            ->whereNotNull('stable_key')
            ->get()
            ->keyBy('stable_key');
        $existingItemsByStableKey = EstimateItem::withTrashed()
            ->where('estimate_id', $estimate->id)
            ->whereNotNull('stable_key')
            ->get()
            ->keyBy('stable_key');
        $sectionIdsByStableKey = [];
        $restoredSectionIds = [];
        $restoredItemIds = [];
        $pendingParentAssignments = [];

        foreach ($snapshot['sections'] ?? [] as $sectionPayload) {
            $this->restoreSection(
                $estimate,
                $sectionPayload,
                null,
                $existingSectionsByStableKey,
                $existingItemsByStableKey,
                $sectionIdsByStableKey,
                $restoredSectionIds,
                $restoredItemIds,
                $pendingParentAssignments
            );
        }

        foreach ($snapshot['unsectioned_items'] ?? [] as $itemPayload) {
            $this->restoreItem(
                $estimate,
                $itemPayload,
                null,
                null,
                $existingItemsByStableKey,
                $sectionIdsByStableKey,
                $restoredItemIds,
                $pendingParentAssignments
            );
        }

        $this->applyParentAssignments($pendingParentAssignments);
        $this->deleteStaleItems($estimate, $restoredItemIds);
        $this->deleteStaleSections($estimate, $restoredSectionIds);
    }

    private function restoreSection(
        Estimate $estimate,
        array $sectionPayload,
        ?int $parentSectionId,
        Collection $existingSectionsByStableKey,
        Collection $existingItemsByStableKey,
        array &$sectionIdsByStableKey,
        array &$restoredSectionIds,
        array &$restoredItemIds,
        array &$pendingParentAssignments
    ): EstimateSection {
        $attributes = [
            'estimate_id' => $estimate->id,
            'stable_key' => $sectionPayload['stable_key'] ?? null,
            'parent_section_id' => $parentSectionId,
            'section_number' => $sectionPayload['section_number'] ?? '',
            'full_section_number' => $sectionPayload['full_section_number'] ?? null,
            'name' => $sectionPayload['name'] ?? '',
            'description' => $sectionPayload['description'] ?? null,
            'sort_order' => $sectionPayload['sort_order'] ?? 0,
            'is_summary' => $sectionPayload['is_summary'] ?? false,
            'section_total_amount' => $sectionPayload['section_total_amount'] ?? 0,
        ];

        $stableKey = $sectionPayload['stable_key'] ?? null;
        $section = $stableKey !== null ? $existingSectionsByStableKey->get($stableKey) : null;

        if ($section instanceof EstimateSection) {
            $section->forceFill($attributes)->save();
        } else {
            $section = EstimateSection::query()->create($attributes);
        }

        $restoredSectionIds[] = $section->id;

        if ($section->stable_key !== null) {
            $sectionIdsByStableKey[$section->stable_key] = $section->id;
        }

        foreach ($sectionPayload['items'] ?? [] as $itemPayload) {
            $this->restoreItem(
                $estimate,
                $itemPayload,
                $section->id,
                null,
                $existingItemsByStableKey,
                $sectionIdsByStableKey,
                $restoredItemIds,
                $pendingParentAssignments
            );
        }

        foreach ($sectionPayload['children'] ?? [] as $childPayload) {
            $this->restoreSection(
                $estimate,
                $childPayload,
                $section->id,
                $existingSectionsByStableKey,
                $existingItemsByStableKey,
                $sectionIdsByStableKey,
                $restoredSectionIds,
                $restoredItemIds,
                $pendingParentAssignments
            );
        }

        return $section;
    }

    private function restoreItem(
        Estimate $estimate,
        array $itemPayload,
        ?int $sectionId,
        ?int $parentItemId,
        Collection $existingItemsByStableKey,
        array $sectionIdsByStableKey,
        array &$restoredItemIds,
        array &$pendingParentAssignments
    ): EstimateItem {
        $resolvedSectionId = $this->resolveSectionId($itemPayload, $sectionId, $sectionIdsByStableKey);
        $attributes = array_merge(
            $this->only($itemPayload, [
                'stable_key',
                'catalog_item_id',
                'normative_rate_id',
                'normative_rate_code',
                'item_type',
                'position_number',
                'name',
                'description',
                'work_type_id',
                'material_id',
                'quantity',
                'quantity_coefficient',
                'quantity_total',
                'actual_quantity',
                'unit_price',
                'base_unit_price',
                'price_index',
                'current_unit_price',
                'price_coefficient',
                'actual_unit_price',
                'direct_costs',
                'materials_cost',
                'machinery_cost',
                'labor_cost',
                'equipment_cost',
                'labor_hours',
                'machinery_hours',
                'base_materials_cost',
                'base_machinery_cost',
                'base_labor_cost',
                'materials_index',
                'machinery_index',
                'labor_index',
                'overhead_amount',
                'profit_amount',
                'total_amount',
                'current_total_amount',
                'justification',
                'notes',
                'is_manual',
                'is_not_accounted',
                'procurement_status',
                'metadata',
                'applied_coefficients',
                'coefficient_total',
                'resource_calculation',
                'custom_resources',
            ]),
            [
                'estimate_id' => $estimate->id,
                'estimate_section_id' => $resolvedSectionId,
                'measurement_unit_id' => $itemPayload['measurement_unit']['id'] ?? null,
            ]
        );

        $stableKey = $itemPayload['stable_key'] ?? null;
        $item = $stableKey !== null ? $existingItemsByStableKey->get($stableKey) : null;

        if ($item instanceof EstimateItem) {
            if ($item->trashed()) {
                $item->restore();
            }

            $item->forceFill($attributes)->save();
        } else {
            $item = EstimateItem::query()->create($attributes);
        }

        $restoredItemIds[] = $item->id;
        $pendingParentAssignments[$item->id] = $parentItemId;

        foreach ($itemPayload['children'] ?? [] as $childPayload) {
            $this->restoreItem(
                $estimate,
                $childPayload,
                null,
                $item->id,
                $existingItemsByStableKey,
                $sectionIdsByStableKey,
                $restoredItemIds,
                $pendingParentAssignments
            );
        }

        return $item;
    }

    private function applyParentAssignments(array $pendingParentAssignments): void
    {
        foreach ($pendingParentAssignments as $itemId => $parentItemId) {
            EstimateItem::query()
                ->whereKey($itemId)
                ->update(['parent_work_id' => $parentItemId]);
        }
    }

    private function deleteStaleItems(Estimate $estimate, array $restoredItemIds): void
    {
        EstimateItem::query()
            ->where('estimate_id', $estimate->id)
            ->when($restoredItemIds !== [], fn ($query) => $query->whereNotIn('id', $restoredItemIds))
            ->delete();
    }

    private function deleteStaleSections(Estimate $estimate, array $restoredSectionIds): void
    {
        EstimateSection::query()
            ->where('estimate_id', $estimate->id)
            ->when($restoredSectionIds !== [], fn ($query) => $query->whereNotIn('id', $restoredSectionIds))
            ->orderByDesc('id')
            ->delete();
    }

    private function resolveSectionId(array $itemPayload, ?int $fallbackSectionId, array $sectionIdsByStableKey): ?int
    {
        if (!array_key_exists('estimate_section_stable_key', $itemPayload)) {
            return $fallbackSectionId;
        }

        $sectionStableKey = $itemPayload['estimate_section_stable_key'];

        if ($sectionStableKey !== null && isset($sectionIdsByStableKey[$sectionStableKey])) {
            return $sectionIdsByStableKey[$sectionStableKey];
        }

        return $fallbackSectionId;
    }

    private function loadRestoredEstimate(Estimate $estimate): Estimate
    {
        $restoredEstimate = $estimate->fresh()->load([
            'approvedBy',
            'project',
        ]);

        $restoredEstimate->setRelation(
            'sections',
            EstimateSection::query()
                ->where('estimate_id', $estimate->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
        $restoredEstimate->setRelation(
            'items',
            EstimateItem::query()
                ->with('measurementUnit')
                ->where('estimate_id', $estimate->id)
                ->orderBy('id')
                ->get()
        );

        return $restoredEstimate;
    }

    private function only(array $payload, array $keys): array
    {
        return array_intersect_key($payload, array_flip($keys));
    }
}
