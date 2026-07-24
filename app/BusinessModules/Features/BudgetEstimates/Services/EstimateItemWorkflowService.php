<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Support\EstimatePositionOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class EstimateItemWorkflowService
{
    public function __construct(
        private readonly EstimateItemService $itemService,
        private readonly EstimateItemNumberingService $numberingService,
        private readonly EstimateCacheService $cacheService,
    ) {
    }

    public function create(Estimate $estimate, array $data): EstimateItem
    {
        $data['estimate_id'] = $estimate->id;

        return $this->itemService->addItem($data, $estimate);
    }

    public function bulkCreate(Estimate $estimate, array $items): array
    {
        return $this->itemService->bulkAdd($items, $estimate);
    }

    public function update(EstimateItem $item, array $data): EstimateItem
    {
        return $this->itemService->updateItem($item, $data);
    }

    public function bulkUpdate(Estimate $estimate, array $items): array
    {
        $updatedItems = $this->itemService->bulkUpdate($estimate, $items);
        $this->cacheService->invalidateStructure($estimate);

        return $updatedItems;
    }

    public function delete(EstimateItem $item): void
    {
        $this->itemService->deleteItem($item);
    }

    public function moveToSection(EstimateItem $item, int $sectionId): EstimateItem
    {
        return $this->itemService->moveToSection($item, $sectionId);
    }

    public function reorder(Estimate $estimate, array $items, string $numberingMode): array
    {
        DB::transaction(function () use ($estimate, $items, $numberingMode): void {
            $models = EstimateItem::query()
                ->where('estimate_id', $estimate->id)
                ->whereIn('id', collect($items)->pluck('id')->all())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $itemData) {
                $item = $models->get((int) $itemData['id']);

                if (!$item) {
                    continue;
                }

                $item->update([
                    'estimate_section_id' => $itemData['estimate_section_id'] ?? null,
                ]);
            }

            $orderedItemIds = collect($items)
                ->values()
                ->sortBy(static fn (array $item, int $index): string => sprintf(
                    '%020d-%020d',
                    (int) $item['sort_order'],
                    $index
                ))
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $this->numberingService->recalculateAllItemNumbers($estimate->id, $numberingMode, $orderedItemIds);
            $this->cacheService->invalidateStructure($estimate);
        });

        return EstimatePositionOrder::apply(
            $estimate->items()
                ->with(['workType', 'measurementUnit', 'section'])
                ->orderBy('estimate_section_id')
        )->get()->all();
    }

    public function recalculateNumbers(Estimate $estimate, string $numberingMode): void
    {
        $this->numberingService->recalculateAllItemNumbers($estimate->id, $numberingMode);
        $this->cacheService->invalidateStructure($estimate);
    }

    public function logFailure(string $operation, Estimate $estimate, Throwable $exception, array $context = []): void
    {
        Log::error("estimate.items.{$operation}.error", array_merge([
            'estimate_id' => $estimate->id,
            'organization_id' => $estimate->organization_id,
            'project_id' => $estimate->project_id,
            'error' => $exception->getMessage(),
        ], $context));
    }
}
