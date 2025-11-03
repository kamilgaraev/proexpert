<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\WorkType;
use App\Repositories\EstimateItemRepository;
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
            $this->repository->update($item, $data);
            
            $estimate = $item->estimate;
            $this->calculationService->calculateItemTotal($item, $estimate);
            
            if ($item->section) {
                $this->calculationService->calculateSectionTotal($item->section);
            }
            
            $this->calculationService->calculateEstimateTotal($estimate);
            
            return $item->fresh();
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
}

