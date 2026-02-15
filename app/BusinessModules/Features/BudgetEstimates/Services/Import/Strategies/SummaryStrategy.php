<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Strategies;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateItemService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportContext;
use App\Models\EstimateItem;

class SummaryStrategy extends BaseItemStrategy
{
    public function __construct(
        private EstimateItemService $itemService
    ) {}

    public function canHandle(EstimateImportRowDTO $row): bool
    {
        return $row->itemType === 'summary';
    }

    public function process(EstimateImportRowDTO $row, ImportContext $context): ?EstimateItem
    {
        $costs = $this->calculateCosts($row);
        $unitId = $this->findOrCreateUnit($row->unit, $context->organizationId);

        $itemData = [
            'estimate_id' => $context->estimate->id,
            'estimate_section_id' => $context->currentSectionId,
            'parent_work_id' => null, 
            'item_type' => 'summary',
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
            'overhead_amount' => $row->overheadAmount ?? 0,
            'profit_amount' => $row->profitAmount ?? 0,
        ];

        return $this->itemService->addItem($itemData, $context->estimate);
    }
}
