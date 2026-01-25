<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Strategies;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\Models\MeasurementUnit;
use Illuminate\Support\Facades\Log;

abstract class BaseItemStrategy implements ItemImportStrategyInterface
{
    protected function findOrCreateUnit(?string $unitName, int $organizationId): ?int
    {
        if (empty($unitName)) {
            return null;
        }

        $normalized = mb_strtolower(trim($unitName));
        
        // Попытка найти существующую
        $unit = MeasurementUnit::where('organization_id', $organizationId)
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->first();
        
        if ($unit === null) {
            try {
                $shortName = mb_strlen($unitName) > 10 
                    ? mb_substr($unitName, 0, 10) 
                    : $unitName;
                
                $unit = MeasurementUnit::create([
                    'organization_id' => $organizationId,
                    'name' => $unitName,
                    'short_name' => $shortName,
                    'type' => 'work',
                ]);
            } catch (\Exception $e) {
                Log::warning('[EstimateImport] Failed to create unit', [
                    'unit' => $unitName,
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        }
        
        return $unit->id;
    }

    protected function calculateCosts(EstimateImportRowDTO $row): array
    {
        // Fallback: если unit_price = null, используем current_unit_price
        $unitPrice = $row->unitPrice ?? $row->currentUnitPrice ?? 0;
        $quantity = $row->quantity ?? 0;
        
        // ПРИОРИТЕТ: Используем currentTotalAmount как прямые затраты из XML (TotalPos)
        // Это точное значение из сметы, а не расчетное
        $directCosts = $row->currentTotalAmount ?? ($quantity * $unitPrice);
        
        // Если currentTotalAmount есть, пересчитываем unit_price для консистентности
        if ($row->currentTotalAmount !== null && $row->currentTotalAmount > 0 && $quantity > 0) {
            $unitPrice = $row->currentTotalAmount / $quantity;
        }
        
        // total_amount = прямые + НР + СП (если есть)
        $totalAmount = $directCosts + ($row->overheadAmount ?? 0) + ($row->profitAmount ?? 0);
        // Если totalAmount получился меньше прямых затрат, используем прямые
        if ($totalAmount < $directCosts) {
            $totalAmount = $directCosts;
        }
        
        return [
            'unit_price' => $unitPrice,
            'direct_costs' => $directCosts,
            'total_amount' => $totalAmount,
            'quantity' => $quantity
        ];
    }
}
