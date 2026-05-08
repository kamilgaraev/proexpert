<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\WorkType;
use App\Repositories\EstimateItemRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EstimateItemService
{
    public function __construct(
        protected EstimateItemRepository $repository,
        protected EstimateCalculationService $calculationService
    ) {}

    public function addItem(array $data, Estimate $estimate): EstimateItem
    {
        return DB::transaction(function () use ($data, $estimate) {
            // Проверка лимита позиций
            $this->checkItemsLimit($estimate);
            
            if (!isset($data['position_number'])) {
                $data['position_number'] = $this->repository->getNextPositionNumber($estimate->id);
            }
            
            if ((isset($data['overhead_amount']) || isset($data['profit_amount'])) && !isset($data['is_manual'])) {
                $data['is_manual'] = true;
            }
            
            $item = $this->repository->create($data);
            
            $this->calculationService->calculateItemTotal($item, $estimate);
            
            if (isset($data['estimate_section_id'])) {
                $section = $item->section;
                if ($section) {
                    $this->calculationService->calculateSectionTotal($section);
                }
            }
            
            $this->calculationService->calculateEstimateTotal($estimate);
            
            \Log::debug('estimate.item.added', [
                'estimate_id' => $estimate->id,
                'item_id' => $item->id,
                'position_number' => $item->position_number,
                'total_amount' => $item->total_amount,
            ]);
            
            return $item;
        });
    }
    
    /**
     * Проверить лимит позиций в смете
     */
    private function checkItemsLimit(Estimate $estimate): void
    {
        $module = app(\App\BusinessModules\Features\BudgetEstimates\BudgetEstimatesModule::class);
        $limits = $module->getLimits();
        
        $currentCount = $this->repository->countByEstimate($estimate->id);
        $maxItems = $limits['max_items_per_estimate'];
        
        if ($maxItems && $currentCount >= $maxItems) {
            throw new \DomainException("Достигнут лимит позиций в смете: {$maxItems}");
        }
    }

    public function addItemFromWorkType(WorkType $workType, Estimate $estimate, array $overrides = []): EstimateItem
    {
        $data = array_merge([
            'estimate_id' => $estimate->id,
            'name' => $workType->name,
            'description' => $workType->description,
            'work_type_id' => $workType->id,
            'measurement_unit_id' => $workType->measurement_unit_id,
            'unit_price' => $workType->default_price,
            'quantity' => 1,
            'is_manual' => false,
        ], $overrides);
        
        return $this->addItem($data, $estimate);
    }

    public function updateItem(EstimateItem $item, array $data): EstimateItem
    {
        return DB::transaction(function () use ($item, $data) {
            $oldQuantity = (float) $item->quantity;

            if ((isset($data['overhead_amount']) || isset($data['profit_amount'])) && !isset($data['is_manual'])) {
                $data['is_manual'] = true;
            }

            $this->repository->update($item, $data);
            $item->refresh();

            if (array_key_exists('quantity', $data)) {
                $this->scaleChildrenForQuantity($item, $oldQuantity, (float) $data['quantity'], $item->estimate);
                $item->refresh();
            }
            
            $estimate = $item->estimate;
            $this->calculationService->calculateItemTotal($item, $estimate);
            
            if ($item->section) {
                $this->calculationService->calculateSectionTotal($item->section);
            }
            
            $this->calculationService->calculateEstimateTotal($estimate);
            
            return $item->fresh();
        });
    }

    public function bulkUpdate(Estimate $estimate, array $items): array
    {
        return DB::transaction(function () use ($estimate, $items): array {
            $itemIds = collect($items)->pluck('id')->map(static fn ($id): int => (int) $id)->unique()->values();

            $models = EstimateItem::query()
                ->where('estimate_id', $estimate->id)
                ->whereIn('id', $itemIds)
                ->with(['section', 'resources'])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $updatedItems = collect();
            $sectionsToRecalculate = collect();

            foreach ($items as $itemData) {
                $item = $models->get((int) $itemData['id']);

                if (!$item) {
                    continue;
                }

                $oldSectionId = $item->estimate_section_id;
                $oldQuantity = (float) $item->quantity;
                $data = $this->bulkUpdatePayload($itemData);

                if (array_key_exists('quantity', $data)) {
                    $newQuantity = (float) $data['quantity'];
                    $this->scaleChildrenForQuantity($item, $oldQuantity, $newQuantity, $estimate);
                }

                if ((isset($data['overhead_amount']) || isset($data['profit_amount'])) && !isset($data['is_manual'])) {
                    $data['is_manual'] = true;
                }

                $this->repository->update($item, $data);
                $item->refresh();
                $this->calculationService->calculateItemTotal($item, $estimate);

                $freshItem = $item->fresh(['workType', 'measurementUnit', 'section', 'resources', 'works', 'totals']);
                $updatedItems->push($freshItem);

                if ($oldSectionId) {
                    $sectionsToRecalculate->push($oldSectionId);
                }

                if ($freshItem->estimate_section_id) {
                    $sectionsToRecalculate->push($freshItem->estimate_section_id);
                }
            }

            $this->recalculateSectionsById($sectionsToRecalculate->unique()->values());
            $this->calculationService->calculateEstimateTotal($estimate);

            return $updatedItems->all();
        });
    }

    public function updateQuantity(EstimateItem $item, float $quantity): EstimateItem
    {
        return $this->updateItem($item, ['quantity' => $quantity]);
    }

    public function updatePrice(EstimateItem $item, float $unitPrice): EstimateItem
    {
        return $this->updateItem($item, ['unit_price' => $unitPrice]);
    }

    public function deleteItem(EstimateItem $item): bool
    {
        return DB::transaction(function () use ($item) {
            $estimate = $item->estimate;
            $section = $item->section;
            
            \Log::debug('estimate.item.deleting', [
                'estimate_id' => $estimate->id,
                'item_id' => $item->id,
                'position_number' => $item->position_number,
                'total_amount' => $item->total_amount,
            ]);
            
            $result = $this->repository->delete($item);
            
            if ($section) {
                $this->calculationService->calculateSectionTotal($section);
            }
            
            $this->calculationService->calculateEstimateTotal($estimate);
            
            return $result;
        });
    }

    public function moveToSection(EstimateItem $item, int $newSectionId): EstimateItem
    {
        return DB::transaction(function () use ($item, $newSectionId) {
            $oldSection = $item->section;
            
            $this->repository->moveToSection($item, $newSectionId);
            
            if ($oldSection) {
                $this->calculationService->calculateSectionTotal($oldSection);
            }
            
            $newSection = $item->fresh()->section;
            if ($newSection) {
                $this->calculationService->calculateSectionTotal($newSection);
            }
            
            return $item->fresh();
        });
    }

    public function bulkAdd(array $items, Estimate $estimate): array
    {
        return DB::transaction(function () use ($items, $estimate) {
            // Проверить лимит для массового добавления
            $module = app(\App\BusinessModules\Features\BudgetEstimates\BudgetEstimatesModule::class);
            $limits = $module->getLimits();
            $maxItems = $limits['max_items_per_estimate'];
            
            $currentCount = $this->repository->countByEstimate($estimate->id);
            $newItemsCount = count($items);
            
            if ($maxItems && ($currentCount + $newItemsCount) > $maxItems) {
                throw new \DomainException(
                    "Массовое добавление невозможно. Будет превышен лимит позиций: {$maxItems}. " .
                    "Текущее количество: {$currentCount}, добавляется: {$newItemsCount}"
                );
            }
            
            $createdItems = [];
            
            foreach ($items as $itemData) {
                $itemData['estimate_id'] = $estimate->id;
                
                if (!isset($itemData['position_number'])) {
                    $itemData['position_number'] = $this->repository->getNextPositionNumber($estimate->id);
                }
                
                if ((isset($itemData['overhead_amount']) || isset($itemData['profit_amount'])) && !isset($itemData['is_manual'])) {
                    $itemData['is_manual'] = true;
                }
                
                $item = $this->repository->create($itemData);
                $this->calculationService->calculateItemTotal($item, $estimate);
                
                $createdItems[] = $item;
            }
            
            $sections = collect($createdItems)
                ->filter(fn($item) => $item->section)
                ->map(fn($item) => $item->section)
                ->unique('id');
            
            foreach ($sections as $section) {
                $this->calculationService->calculateSectionTotal($section);
            }
            
            $this->calculationService->calculateEstimateTotal($estimate);
            
            return $createdItems;
        });
    }

    private function bulkUpdatePayload(array $itemData): array
    {
        $allowed = [
            'estimate_section_id',
            'item_type',
            'position_number',
            'name',
            'description',
            'work_type_id',
            'measurement_unit_id',
            'quantity',
            'quantity_coefficient',
            'quantity_total',
            'unit_price',
            'base_unit_price',
            'price_index',
            'current_unit_price',
            'price_coefficient',
            'current_total_amount',
            'labor_hours',
            'machinery_hours',
            'materials_cost',
            'machinery_cost',
            'labor_cost',
            'equipment_cost',
            'direct_costs',
            'overhead_amount',
            'profit_amount',
            'justification',
            'is_manual',
            'metadata',
        ];

        if (array_key_exists('section_id', $itemData) && !array_key_exists('estimate_section_id', $itemData)) {
            $itemData['estimate_section_id'] = $itemData['section_id'];
        }

        return array_intersect_key($itemData, array_flip($allowed));
    }

    private function scaleChildrenForQuantity(EstimateItem $item, float $oldQuantity, float $newQuantity, Estimate $estimate): void
    {
        $children = EstimateItem::query()
            ->where('parent_work_id', $item->id)
            ->where('estimate_id', $estimate->id)
            ->lockForUpdate()
            ->get();

        if ($children->isEmpty()) {
            return;
        }

        foreach ($children as $child) {
            $quantityPerUnit = $child->metadata['quantity_per_unit'] ?? null;
            $quantity = is_numeric($quantityPerUnit)
                ? round((float) $quantityPerUnit * $newQuantity, 8)
                : $this->scaleQuantity((float) $child->quantity, $oldQuantity, $newQuantity);
            $itemType = $child->item_type->value ?? $child->item_type;
            $total = round($quantity * (float) $child->unit_price, 2);

            $child->quantity = $quantity;
            $child->quantity_total = $quantity;
            $child->direct_costs = $total;
            $child->materials_cost = $itemType === \App\Enums\EstimatePositionItemType::MATERIAL->value ? $total : 0;
            $child->machinery_cost = $itemType === \App\Enums\EstimatePositionItemType::MACHINERY->value ? $total : 0;
            $child->labor_cost = $itemType === \App\Enums\EstimatePositionItemType::LABOR->value ? $total : 0;
            $child->total_amount = $total;
            $child->current_total_amount = $total;

            if ($itemType === \App\Enums\EstimatePositionItemType::LABOR->value) {
                $child->labor_hours = $quantity;
            }

            if ($itemType === \App\Enums\EstimatePositionItemType::MACHINERY->value) {
                $child->machinery_hours = $quantity;
            }

            $child->save();
            $this->calculationService->calculateItemTotal($child, $estimate);
        }

        $item->resources->each(function ($resource) use ($newQuantity): void {
            $quantityPerUnit = (float) ($resource->quantity_per_unit ?? 0);
            $totalQuantity = round($quantityPerUnit * $newQuantity, 4);

            $resource->update([
                'total_quantity' => $totalQuantity,
                'total_amount' => round($totalQuantity * (float) $resource->unit_price, 2),
            ]);
        });
    }

    private function scaleQuantity(float $currentQuantity, float $oldQuantity, float $newQuantity): float
    {
        if ($oldQuantity <= 0) {
            return $currentQuantity;
        }

        return round($currentQuantity * ($newQuantity / $oldQuantity), 8);
    }

    private function recalculateSectionsById(Collection $sectionIds): void
    {
        if ($sectionIds->isEmpty()) {
            return;
        }

        \App\Models\EstimateSection::query()
            ->whereIn('id', $sectionIds->all())
            ->get()
            ->each(fn ($section) => $this->calculationService->calculateSectionTotal($section));
    }
}

