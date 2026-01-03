<?php

namespace App\BusinessModules\Features\BudgetEstimates\Contracts;

use App\BusinessModules\Features\BudgetEstimates\DTOs\ClassificationResult;

interface ClassificationStrategyInterface
{
    public function classify(string $code, string $name, ?string $unit = null, ?float $price = null): ?ClassificationResult;
    
    /**
     * @param array $items Array of items, each having 'code', 'name', 'unit', 'price' keys
     * @return array<int, ClassificationResult>
     */
    public function classifyBatch(array $items): array;
    
    public function getName(): string;
}
