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
        
        $directCosts = $quantity * $unitPrice;
        $totalAmount = $row->currentTotalAmount ?? $directCosts;
        
        return [
            'unit_price' => $unitPrice,
            'direct_costs' => $directCosts,
            'total_amount' => $totalAmount,
            'quantity' => $quantity
        ];
    }
}
