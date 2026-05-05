<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Versioning;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateVersioningService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\EstimateVersion;
use Illuminate\Support\Facades\DB;
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
        EstimateItem::withTrashed()
            ->where('estimate_id', $estimate->id)
            ->forceDelete();
        EstimateSection::query()
            ->where('estimate_id', $estimate->id)
            ->delete();

        $sectionIdsByStableKey = [];

        foreach ($snapshot['sections'] ?? [] as $sectionPayload) {
            $this->createSection($estimate, $sectionPayload, null, $sectionIdsByStableKey);
        }

        foreach ($snapshot['unsectioned_items'] ?? [] as $itemPayload) {
            $this->createItem($estimate, $itemPayload, null, null, $sectionIdsByStableKey);
        }
    }

    private function createSection(
        Estimate $estimate,
        array $sectionPayload,
        ?int $parentSectionId,
        array &$sectionIdsByStableKey
    ): EstimateSection {
        $section = EstimateSection::query()->create([
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
        ]);

        if ($section->stable_key !== null) {
            $sectionIdsByStableKey[$section->stable_key] = $section->id;
        }

        foreach ($sectionPayload['items'] ?? [] as $itemPayload) {
            $this->createItem($estimate, $itemPayload, $section->id, null, $sectionIdsByStableKey);
        }

        foreach ($sectionPayload['children'] ?? [] as $childPayload) {
            $this->createSection($estimate, $childPayload, $section->id, $sectionIdsByStableKey);
        }

        return $section;
    }

    private function createItem(
        Estimate $estimate,
        array $itemPayload,
        ?int $sectionId,
        ?int $parentItemId,
        array $sectionIdsByStableKey
    ): EstimateItem {
        $resolvedSectionId = $this->resolveSectionId($itemPayload, $sectionId, $sectionIdsByStableKey);
        $item = EstimateItem::query()->create(array_merge(
            $this->only($itemPayload, [
                'stable_key',
                'catalog_item_id',
                'normative_rate_id',
                'normative_rate_code',
                'item_type',
                'position_number',
                'name',
                'description',
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
                'base_materials_cost',
                'base_machinery_cost',
                'base_labor_cost',
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
                'resource_calculation',
                'custom_resources',
            ]),
            [
                'estimate_id' => $estimate->id,
                'estimate_section_id' => $resolvedSectionId,
                'parent_work_id' => $parentItemId,
                'measurement_unit_id' => $itemPayload['measurement_unit']['id'] ?? null,
            ]
        ));

        foreach ($itemPayload['children'] ?? [] as $childPayload) {
            $this->createItem($estimate, $childPayload, null, $item->id, $sectionIdsByStableKey);
        }

        return $item;
    }

    private function resolveSectionId(array $itemPayload, ?int $fallbackSectionId, array $sectionIdsByStableKey): ?int
    {
        $sectionStableKey = $itemPayload['estimate_section_stable_key'] ?? null;

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
