<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Strategies;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateItemService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportContext;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ResourceMatchingService;
use App\Models\EstimateItem;
use App\Models\EstimateItemTotal;
use App\Models\EstimateItemWork;
use Illuminate\Support\Facades\Log;

class ResourceStrategy extends BaseItemStrategy
{
    public function __construct(
        private EstimateItemService $itemService,
        private ResourceMatchingService $resourceMatchingService
    ) {}

    public function canHandle(EstimateImportRowDTO $row): bool
    {
        return in_array($row->itemType, ['material', 'equipment', 'machinery', 'labor']);
    }

    public function process(EstimateImportRowDTO $row, ImportContext $context): ?EstimateItem
    {
        $costs = $this->calculateCosts($row);
        $unitId = $this->findOrCreateUnit($row->unit, $context->organizationId);

        $itemData = [
            'estimate_id' => $context->estimate->id,
            'estimate_section_id' => $context->currentSectionId,
            'position_number' => $row->sectionNumber ?: null, // Pass explicit number from XML if available
            'parent_work_id' => $context->currentWorkId, // ⭐ Привязка к родительской работе
            'item_type' => $row->itemType,
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
            'overhead_amount' => $row->overheadAmount,
            'profit_amount' => $row->profitAmount,
            'is_manual' => $row->isManual,
        ];

        if (!empty($row->code)) {
            try {
                // Универсальный поиск/создание ресурса
                $result = $this->resourceMatchingService->findOrCreate(
                    $row->itemType === 'equipment' ? 'material' : $row->itemType,
                    $row->code,
                    $row->itemName,
                    $row->unit,
                    $costs['unit_price'],
                    $context->organizationId,
                    [
                        'item_type' => $row->itemType,
                        'is_not_accounted' => $row->isNotAccounted,
                    ]
                );
                
                // Связываем с позицией сметы
                match ($result['type']) {
                    'material' => $itemData['material_id'] = $result['resource']->id,
                    'machinery' => $itemData['machinery_id'] = $result['resource']->id,
                    'labor' => $itemData['labor_resource_id'] = $result['resource']->id,
                    default => null,
                };
                
            } catch (\Exception $e) {
                Log::warning('resource_strategy.resource_failed', [
                    'type' => $row->itemType,
                    'code' => $row->code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $createdItem = $this->itemService->addItem($itemData, $context->estimate);

        // Сохранение WorksList (если позиция пришла как material/equipment, но содержит работы)
        if (!empty($row->worksList)) {
            foreach ($row->worksList as $workData) {
                EstimateItemWork::create([
                    'estimate_item_id' => $createdItem->id,
                    'caption' => $workData['caption'] ?? '',
                    'sort_order' => (int)($workData['sort_order'] ?? 0),
                    'metadata' => $workData['metadata'] ?? null,
                ]);
            }
        }

        // Сохранение Totals (если позиция содержит Itog-структуру)
        if (!empty($row->totals)) {
            foreach ($row->totals as $totalData) {
                EstimateItemTotal::create([
                    'estimate_item_id' => $createdItem->id,
                    'data_type' => $totalData['data_type'] ?? null,
                    'caption' => $totalData['caption'] ?? null,
                    'quantity_for_one' => $totalData['quantity_for_one'] ?? null,
                    'quantity_total' => $totalData['quantity_total'] ?? null,
                    'for_one_curr' => $totalData['for_one_curr'] ?? null,
                    'total_curr' => $totalData['total_curr'] ?? null,
                    'total_base' => $totalData['total_base'] ?? null,
                    'sort_order' => (int)($totalData['sort_order'] ?? 0),
                    'metadata' => $totalData['metadata'] ?? null,
                ]);
            }
        }

        return $createdItem;
    }
}
