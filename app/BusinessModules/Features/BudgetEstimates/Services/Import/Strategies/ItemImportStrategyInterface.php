<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Strategies;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportContext;
use App\Models\EstimateItem;

interface ItemImportStrategyInterface
{
    public function canHandle(EstimateImportRowDTO $row): bool;
    
    public function process(EstimateImportRowDTO $row, ImportContext $context): ?EstimateItem;
}
