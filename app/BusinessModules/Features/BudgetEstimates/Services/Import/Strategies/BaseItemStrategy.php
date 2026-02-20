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
        // Используем whereRaw с правильной параметризацией через bindings
        $unit = MeasurementUnit::where('organization_id', $organizationId)
            ->whereRaw('LOWER(TRIM(name)) = LOWER(TRIM(?))', [$normalized])
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
        $quantity = $row->quantity ?? 0;
        $unitPrice = $row->unitPrice ?? $row->currentUnitPrice ?? 0;
        
        // В GrandSmeta (свернутые форматы) currentTotalAmount (Всего по позиции) уже может включать в себя 
        // прямые затраты + индексы + НР + СП.
        // То есть это полноценный total_amount, а не только прямые затраты.
        
        $totalAmount = $row->currentTotalAmount > 0 ? $row->currentTotalAmount : ($quantity * $unitPrice);

        // Если нам передали только TotalAmount (и он больше 0), а цена за единицу пустая,
        // вычисляем ее обратным счетом.
        if ($totalAmount > 0 && $quantity > 0 && $unitPrice <= 0) {
            $unitPrice = $totalAmount / $quantity;
        }

        // Прямые затраты в идеале должны парситься из ресурсов (суммироваться потом на уровне EstimateCalculator).
        // Но для начального сохранения в базу мы можем записать total_amount как direct_costs (грязный вариант),
        // либо, если парсер передал overheadAmount/profitAmount, вычистить их.
        $overhead = $row->overheadAmount ?? 0;
        $profit = $row->profitAmount ?? 0;
        
        // Если у нас есть totalAmount, но нет явного разделения, запишем все в прямые затраты для сохранения баланса.
        // Позже EstimateCalculator сможет сделать реверс-маркап (отнять от total_amount сумму ресурсов и вычислить НР/СП).
        $directCosts = $totalAmount - $overhead - $profit;
        if ($directCosts < 0) {
            $directCosts = $totalAmount; // Fallback
        }

        return [
            'unit_price' => $unitPrice,
            'direct_costs' => $directCosts, // С грязной сметой сюда попадает totalAmount (до пересчета калькулятором)
            'total_amount' => $totalAmount, // Это наша фиксированная целевая сумма (16 668,12)
            'quantity' => $quantity
        ];
    }
}
