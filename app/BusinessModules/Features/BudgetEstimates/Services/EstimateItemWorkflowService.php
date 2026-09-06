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
        private readonly EstimateCalculationService $calculationService,
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

    public function moveToSection(
        EstimateItem $item,
        ?int $sectionId,
        ?int $anchorItemId = null,
        string $placement = 'after'
    ): EstimateItem {
        return DB::transaction(function () use ($item, $sectionId, $anchorItemId, $placement): EstimateItem {
            $estimate = Estimate::query()->whereKey($item->estimate_id)->lockForUpdate()->firstOrFail();
            $item = $estimate->items()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if (!in_array($placement, ['before', 'after'], true)) {
                throw new \DomainException(trans_message('estimate.validation_error'));
            }

            if ($sectionId !== null && !$estimate->sections()->whereKey($sectionId)->exists()) {
                throw new \DomainException(trans_message('estimate.validation_error'));
            }

            if ($item->parent_work_id !== null && $item->estimate_section_id !== $sectionId) {
                throw new \DomainException(trans_message('estimate.validation_error'));
            }

            $siblings = EstimatePositionOrder::apply(
                $estimate->items()->where('estimate_section_id', $sectionId)
                    ->where('parent_work_id', $item->parent_work_id)->whereKeyNot($item->id)
            )->get();
            $insertAt = $siblings->count();

            if ($anchorItemId !== null) {
                $anchorIndex = $siblings->search(static fn (EstimateItem $sibling): bool => $sibling->id === $anchorItemId);

                if ($anchorIndex === false) {
                    throw new \DomainException(trans_message('estimate.validation_error'));
                }

                $insertAt = $anchorIndex + ($placement === 'after' ? 1 : 0);
            }

            $siblings->splice($insertAt, 0, [$item]);
            $items = $siblings->values()->map(static fn (EstimateItem $sibling, int $index): array => [
                'id' => $sibling->id,
                'estimate_section_id' => $sectionId,
                'sort_order' => $index,
            ])->all();

            $this->reorder($estimate, $items, null);

            return $item->fresh();
        });
    }

    public function reorder(Estimate $estimate, array $items, ?string $numberingMode): array
    {
        DB::transaction(function () use ($estimate, $items, $numberingMode): void {
            $estimate = Estimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();
            $numberingMode ??= $this->numberingMode($estimate);
            $models = EstimateItem::query()
                ->where('estimate_id', $estimate->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $requestedSections = [];

            foreach ($items as $itemData) {
                $requestedSections[(int) $itemData['id']] = isset($itemData['estimate_section_id'])
                    ? (int) $itemData['estimate_section_id']
                    : null;
            }

            $resolvedSections = [];
            $resolveSection = function (EstimateItem $item, array $ancestors = []) use (&$resolveSection, &$resolvedSections, $requestedSections, $models): ?int {
                if (array_key_exists($item->id, $resolvedSections)) {
                    return $resolvedSections[$item->id];
                }

                if (isset($ancestors[$item->id])) {
                    throw new \DomainException(trans_message('estimate.validation_error'));
                }

                $ancestors[$item->id] = true;
                $sectionId = array_key_exists($item->id, $requestedSections)
                    ? $requestedSections[$item->id]
                    : $item->estimate_section_id;

                if ($item->parent_work_id !== null) {
                    $parent = $models->get($item->parent_work_id);
                    if (!$parent) {
                        throw new \DomainException(trans_message('estimate.validation_error'));
                    }

                    $parentSectionId = $resolveSection($parent, $ancestors);
                    if (array_key_exists($item->id, $requestedSections) && $sectionId !== $parentSectionId) {
                        throw new \DomainException(trans_message('estimate.validation_error'));
                    }
                    $sectionId = $parentSectionId;
                }

                return $resolvedSections[$item->id] = $sectionId;
            };

            foreach ($models as $item) {
                $resolveSection($item);
            }

            $sectionsChanged = false;
            foreach ($models as $item) {
                $sectionId = $resolvedSections[$item->id];
                if ($item->estimate_section_id !== $sectionId) {
                    $item->update(['estimate_section_id' => $sectionId]);
                    $sectionsChanged = true;
                }
            }

            if ($sectionsChanged) {
                $roots = $estimate->sections()->whereNull('parent_section_id')->get();
                foreach ($roots as $root) {
                    $this->calculationService->calculateSectionTotal($root);
                }
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
            $estimate->update(['metadata' => array_merge($estimate->metadata ?? [], ['numbering_mode' => $numberingMode])]);
            DB::afterCommit(fn () => $this->cacheService->invalidateStructure($estimate));
        });

        return EstimatePositionOrder::apply(
            $estimate->items()
                ->with(['workType', 'measurementUnit', 'section'])
                ->orderBy('estimate_section_id')
        )->get()->all();
    }

    public function recalculateNumbers(Estimate $estimate, string $numberingMode): void
    {
        DB::transaction(function () use ($estimate, $numberingMode): void {
            $estimate = Estimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();
            $this->numberingService->recalculateAllItemNumbers($estimate->id, $numberingMode);
            $estimate->update(['metadata' => array_merge($estimate->metadata ?? [], ['numbering_mode' => $numberingMode])]);
            DB::afterCommit(fn () => $this->cacheService->invalidateStructure($estimate));
        });
    }

    private function numberingMode(Estimate $estimate): string
    {
        $mode = $estimate->metadata['numbering_mode'] ?? null;
        if (in_array($mode, ['global', 'section', 'hierarchical'], true)) {
            return $mode;
        }

        $items = $estimate->items()->get(['position_number', 'estimate_section_id']);
        if ($items->contains(static fn (EstimateItem $item): bool => str_contains($item->position_number, '.'))) {
            return EstimateItemNumberingService::NUMBERING_HIERARCHICAL;
        }

        $numbers = $items->pluck('position_number')
            ->filter(static fn (string $number): bool => preg_match('/^[1-9][0-9]*$/', $number) === 1)
            ->map(static fn (string $number): int => (int) $number)->sort()->values()->all();
        if ($items->isNotEmpty() && $items->pluck('estimate_section_id')->unique()->count() > 1
            && $numbers === range(1, $items->count())) {
            return EstimateItemNumberingService::NUMBERING_GLOBAL;
        }

        return EstimateItemNumberingService::NUMBERING_BY_SECTION;
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
