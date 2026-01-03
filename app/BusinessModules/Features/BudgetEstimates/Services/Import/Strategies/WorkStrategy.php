<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Strategies;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateItemService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportContext;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\NormativeMatchingService;
use App\Models\EstimateItem;
use Illuminate\Support\Facades\Log;

class WorkStrategy extends BaseItemStrategy
{
    public function __construct(
        private EstimateItemService $itemService,
        private NormativeMatchingService $normativeMatchingService
    ) {}

    public function canHandle(EstimateImportRowDTO $row): bool
    {
        return $row->itemType === 'work';
    }

    public function process(EstimateImportRowDTO $row, ImportContext $context): ?EstimateItem
    {
        $costs = $this->calculateCosts($row);
        $unitId = $this->findOrCreateUnit($row->unit, $context->organizationId);

        $itemData = [
            'estimate_id' => $context->estimate->id,
            'estimate_section_id' => $context->currentSectionId,
            'parent_work_id' => null, 
            'item_type' => 'work',
            'name' => $row->itemName,
            'measurement_unit_id' => $unitId,
            'normative_rate_code' => $row->code,
            'quantity' => $costs['quantity'],
            'quantity_coefficient' => $row->quantityCoefficient,
            'quantity_total' => $row->quantityTotal,
            'unit_price' => $costs['unit_price'],
            'base_unit_price' => $row->baseUnitPrice,
            'price_index' => $row->priceIndex,
            'current_unit_price' => $row->currentUnitPrice,
            'price_coefficient' => $row->priceCoefficient,
            'direct_costs' => $costs['direct_costs'],
            'total_amount' => $costs['total_amount'],
            'current_total_amount' => $row->currentTotalAmount,
            'is_not_accounted' => $row->isNotAccounted,
        ];

        // Логика поиска нормативов (перенесена из EstimateImportService)
        if (!empty($row->code)) {
            // Приоритет 1: Поиск по коду
            $normativeMatch = $this->normativeMatchingService->findByCode($row->code, [
                'fallback_to_name' => true,
                'name' => $row->itemName,
            ]);

            if ($normativeMatch) {
                $itemData = $this->normativeMatchingService->fillFromNormative(
                    $normativeMatch['normative'],
                    $itemData
                );
                $context->codeMatchesCount++;
                
                Log::debug('normative.match', [
                     'code' => $row->code,
                     'normative_id' => $normativeMatch['normative']->id,
                     'method' => $normativeMatch['method']
                ]);
            }
        } elseif (!empty($row->itemName)) {
            // Приоритет 2: Поиск по названию
            $nameResults = $this->normativeMatchingService->findByName($row->itemName, 1);
            if ($nameResults->isNotEmpty()) {
                $nameMatch = $nameResults->first();
                $itemData = $this->normativeMatchingService->fillFromNormative(
                    $nameMatch['normative'],
                    $itemData
                );
                $context->nameMatchesCount++;
                
                Log::debug('normative.name_match', [
                    'name' => $row->itemName,
                    'normative_id' => $nameMatch['normative']->id
                ]);
            }
        }

        try {
            $createdItem = $this->itemService->addItem($itemData, $context->estimate);
            
            // ⭐ Обновляем текущую работу для привязки ресурсов (подпозиций)
            $context->currentWorkId = $createdItem->id;
            
            return $createdItem;
        } catch (\Exception $e) {
            Log::error('work_strategy.create_failed', [
                'row' => $row->rowNumber,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
