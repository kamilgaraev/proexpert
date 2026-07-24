<?php

declare(strict_types=1);

namespace App\Services\Estimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Normative\EnhancedCalculationService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimatePositionCatalog;
use App\Models\EstimateSection;
use App\Models\NormativeRate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EstimateConstructorService
{
    public function __construct(
        private readonly EnhancedCalculationService $calculationService,
    ) {
    }

    public function addItemsFromNormatives(int $organizationId, int $estimateId, array $payload): array
    {
        $estimate = $this->findEstimate($organizationId, $estimateId);
        $this->assertSectionsBelongToEstimate($this->sectionIdsFromItems($payload['items']), $estimate->id);

        $addedItems = [];

        DB::transaction(function () use ($estimate, $payload, &$addedItems): void {
            foreach ($payload['items'] as $itemData) {
                $sectionId = $this->nullableInt($itemData['section_id'] ?? null);
                $rate = NormativeRate::with('resources')->findOrFail($itemData['normative_rate_id']);

                $item = new EstimateItem([
                    'estimate_id' => $estimate->id,
                    'estimate_section_id' => $sectionId,
                    'item_type' => 'work',
                    'position_number' => $this->nextPositionNumber($estimate->id, $sectionId),
                ]);

                $item = $this->calculationService->calculateItemFromNormativeRate(
                    $item,
                    $rate,
                    (float) $itemData['quantity'],
                    [
                        'apply_indices' => (bool) ($payload['apply_indices'] ?? false),
                        'calculation_date' => isset($payload['calculation_date'])
                            ? Carbon::parse($payload['calculation_date'])
                            : now(),
                        'coefficients' => $payload['coefficients'] ?? [],
                    ],
                );

                $item->save();
                $addedItems[] = $item->fresh(['normativeRate', 'section']);
            }
        });

        return [
            'added_count' => count($addedItems),
            'items' => $addedItems,
        ];
    }

    public function addItemsFromCatalog(int $organizationId, int $estimateId, array $payload): array
    {
        $estimate = $this->findEstimate($organizationId, $estimateId);
        $this->assertSectionsBelongToEstimate($this->sectionIdsFromItems($payload['items']), $estimate->id);

        $addedItems = [];

        DB::transaction(function () use ($estimate, $payload, &$addedItems): void {
            foreach ($payload['items'] as $itemData) {
                $sectionId = $this->nullableInt($itemData['section_id'] ?? null);
                $catalogItem = EstimatePositionCatalog::query()
                    ->where('organization_id', $estimate->organization_id)
                    ->findOrFail($itemData['catalog_item_id']);

                $quantity = (float) $itemData['quantity'];
                $unitPrice = (float) $catalogItem->unit_price;

                $item = EstimateItem::create([
                    'estimate_id' => $estimate->id,
                    'estimate_section_id' => $sectionId,
                    'catalog_item_id' => $catalogItem->id,
                    'item_type' => $catalogItem->item_type,
                    'position_number' => $this->nextPositionNumber($estimate->id, $sectionId),
                    'name' => $catalogItem->name,
                    'description' => $catalogItem->description,
                    'measurement_unit_id' => $catalogItem->measurement_unit_id,
                    'work_type_id' => $catalogItem->work_type_id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'direct_costs' => $catalogItem->direct_costs ?? ($unitPrice * $quantity),
                    'total_amount' => $unitPrice * $quantity,
                    'is_manual' => true,
                    'metadata' => [
                        'source' => 'catalog',
                        'catalog_item_id' => $catalogItem->id,
                    ],
                ]);

                $catalogItem->incrementUsage();
                $addedItems[] = $item->fresh(['measurementUnit', 'workType', 'catalogItem', 'section']);
            }
        });

        return [
            'added_count' => count($addedItems),
            'items' => $addedItems,
        ];
    }

    public function bulkUpdate(int $organizationId, int $estimateId, array $payload): array
    {
        $estimate = $this->findEstimate($organizationId, $estimateId);
        $items = collect($payload['items']);
        $this->assertItemsBelongToEstimate($items->pluck('id')->map(fn ($id): int => (int) $id)->all(), $estimate->id);
        $this->assertSectionsBelongToEstimate($this->sectionIdsFromItems($payload['items']), $estimate->id);

        $updated = [];

        DB::transaction(function () use ($estimate, $items, &$updated): void {
            foreach ($items as $itemData) {
                $item = EstimateItem::query()
                    ->where('estimate_id', $estimate->id)
                    ->where('id', $itemData['id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->applyItemPatch($item, $itemData);
                $item->save();

                $updated[] = $item->fresh();
            }
        });

        return [
            'updated_count' => count($updated),
            'items' => $updated,
        ];
    }

    public function bulkDelete(int $organizationId, int $estimateId, array $payload): array
    {
        $estimate = $this->findEstimate($organizationId, $estimateId);
        $itemIds = array_map('intval', $payload['item_ids']);
        $this->assertItemsBelongToEstimate($itemIds, $estimate->id);

        $deleted = DB::transaction(
            fn (): int => EstimateItem::query()
                ->where('estimate_id', $estimate->id)
                ->whereIn('id', $itemIds)
                ->delete()
        );

        return ['deleted_count' => $deleted];
    }

    public function reorderItems(int $organizationId, int $estimateId, array $payload): array
    {
        $estimate = $this->findEstimate($organizationId, $estimateId);
        $items = collect($payload['items']);
        $itemIds = $items->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assertItemsBelongToEstimate($itemIds, $estimate->id);
        $this->assertSectionsBelongToEstimate($this->sectionIdsFromItems($payload['items']), $estimate->id);

        DB::transaction(function () use ($estimate, $items): void {
            foreach ($items as $itemData) {
                $sectionId = $this->nullableInt($itemData['section_id'] ?? null);

                EstimateItem::query()
                    ->where('estimate_id', $estimate->id)
                    ->where('id', $itemData['id'])
                    ->lockForUpdate()
                    ->update([
                        'position_number' => $itemData['position_number'],
                        'estimate_section_id' => $sectionId,
                    ]);
            }
        });

        return ['reordered_count' => count($itemIds)];
    }

    public function moveItemsToSection(int $organizationId, int $estimateId, array $payload): array
    {
        $estimate = $this->findEstimate($organizationId, $estimateId);
        $itemIds = array_map('intval', $payload['item_ids']);
        $sectionId = (int) $payload['section_id'];
        $this->assertItemsBelongToEstimate($itemIds, $estimate->id);
        $this->assertSectionBelongsToEstimate($sectionId, $estimate->id);

        $updated = DB::transaction(
            fn (): int => EstimateItem::query()
                ->where('estimate_id', $estimate->id)
                ->whereIn('id', $itemIds)
                ->lockForUpdate()
                ->update(['estimate_section_id' => $sectionId])
        );

        return [
            'moved_count' => $updated,
            'updated_count' => $updated,
        ];
    }

    public function copyItems(int $organizationId, int $estimateId, array $payload): array
    {
        $sourceEstimate = $this->findEstimate($organizationId, $estimateId);
        $targetEstimate = $this->findEstimate($organizationId, (int) $payload['target_estimate_id']);
        $itemIds = array_map('intval', $payload['item_ids']);
        $targetSectionId = $this->nullableInt($payload['target_section_id'] ?? null);

        $this->assertItemsBelongToEstimate($itemIds, $sourceEstimate->id);
        if ($targetSectionId !== null) {
            $this->assertSectionBelongsToEstimate($targetSectionId, $targetEstimate->id);
        }

        $copiedItems = [];

        DB::transaction(function () use ($sourceEstimate, $targetEstimate, $targetSectionId, $itemIds, &$copiedItems): void {
            $items = EstimateItem::query()
                ->where('estimate_id', $sourceEstimate->id)
                ->whereIn('id', $itemIds)
                ->lockForUpdate()
                ->get();

            foreach ($items as $item) {
                $newItem = $item->replicate();
                $newItem->estimate_id = $targetEstimate->id;
                $newItem->estimate_section_id = $targetSectionId;
                $newItem->position_number = $this->nextPositionNumber($targetEstimate->id, $targetSectionId);
                $newItem->save();

                $copiedItems[] = $newItem->fresh();
            }
        });

        return [
            'copied_count' => count($copiedItems),
            'items' => $copiedItems,
        ];
    }

    public function applyCoefficientsToItems(int $organizationId, int $estimateId, array $payload): array
    {
        $estimate = $this->findEstimate($organizationId, $estimateId);
        $itemIds = array_map('intval', $payload['item_ids']);
        $this->assertItemsBelongToEstimate($itemIds, $estimate->id);

        $updated = DB::transaction(
            fn (): int => $this->calculationService->bulkApplyCoefficients($itemIds, $payload['coefficients'])
        );

        return ['updated_count' => $updated];
    }

    public function applyIndicesToItems(int $organizationId, int $estimateId, array $payload): array
    {
        $estimate = $this->findEstimate($organizationId, $estimateId);
        $itemIds = array_map('intval', $payload['item_ids']);
        $this->assertItemsBelongToEstimate($itemIds, $estimate->id);

        $updated = DB::transaction(
            fn (): int => $this->calculationService->bulkApplyIndices($itemIds, Carbon::parse($payload['calculation_date']))
        );

        return ['updated_count' => $updated];
    }

    public function recalculateEstimate(int $organizationId, int $estimateId): array
    {
        $estimate = $this->findEstimate($organizationId, $estimateId);

        return [
            'estimate' => $this->calculationService->recalculateEstimate($estimate, [
                'apply_indices' => true,
                'calculation_date' => now(),
            ]),
        ];
    }

    private function findEstimate(int $organizationId, int $estimateId): Estimate
    {
        return Estimate::query()
            ->where('organization_id', $organizationId)
            ->findOrFail($estimateId);
    }

    private function assertSectionBelongsToEstimate(int $sectionId, int $estimateId): void
    {
        $exists = EstimateSection::query()
            ->where('estimate_id', $estimateId)
            ->where('id', $sectionId)
            ->exists();

        if (!$exists) {
            throw (new ModelNotFoundException())->setModel(EstimateSection::class, [$sectionId]);
        }
    }

    private function assertSectionsBelongToEstimate(array $sectionIds, int $estimateId): void
    {
        if ($sectionIds === []) {
            return;
        }

        $existingCount = EstimateSection::query()
            ->where('estimate_id', $estimateId)
            ->whereIn('id', $sectionIds)
            ->count();

        if ($existingCount !== count(array_unique($sectionIds))) {
            throw (new ModelNotFoundException())->setModel(EstimateSection::class, $sectionIds);
        }
    }

    private function assertItemsBelongToEstimate(array $itemIds, int $estimateId): void
    {
        if ($itemIds === []) {
            throw (new ModelNotFoundException())->setModel(EstimateItem::class);
        }

        $existingCount = EstimateItem::query()
            ->where('estimate_id', $estimateId)
            ->whereIn('id', $itemIds)
            ->count();

        if ($existingCount !== count(array_unique($itemIds))) {
            throw (new ModelNotFoundException())->setModel(EstimateItem::class, $itemIds);
        }
    }

    private function sectionIdsFromItems(array $items): array
    {
        return collect($items)
            ->pluck('section_id')
            ->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function nextPositionNumber(int $estimateId, ?int $sectionId): string
    {
        $query = EstimateItem::query()
            ->where('estimate_id', $estimateId)
            ->lockForUpdate();

        if ($sectionId !== null) {
            $query->where('estimate_section_id', $sectionId);
        } else {
            $query->whereNull('estimate_section_id');
        }

        $maxPosition = $query->max('position_number');

        if (!$maxPosition) {
            return $sectionId !== null ? '1.1' : '1';
        }

        $parts = explode('.', (string) $maxPosition);
        $lastIndex = count($parts) - 1;
        $parts[$lastIndex] = (string) (((int) $parts[$lastIndex]) + 1);

        return implode('.', $parts);
    }

    private function applyItemPatch(EstimateItem $item, array $itemData): void
    {
        $fields = [
            'quantity',
            'unit_price',
            'direct_costs',
            'overhead_amount',
            'profit_amount',
            'total_amount',
            'position_number',
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $itemData)) {
                $item->{$field} = $itemData[$field];
            }
        }

        if (array_key_exists('section_id', $itemData)) {
            $item->estimate_section_id = $this->nullableInt($itemData['section_id']);
        }

        if (array_key_exists('quantity', $itemData) && $item->normativeRate) {
            $this->calculationService->recalculateItem($item);
        }
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
