<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Adapters;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use Illuminate\Support\Facades\Log;

/**
 * Адаптер для SmartSmeta / Smeta.ru
 * 
 * Специфика программы SmartSmeta
 */
class SmartSmetaAdapter implements EstimateAdapterInterface
{
    public function supports(string $estimateType): bool
    {
        return $estimateType === 'smartsmeta';
    }
    
    public function adapt(EstimateImportDTO $dto, array $metadata): EstimateImportDTO
    {
        Log::info('[SmartSmetaAdapter] Starting adaptation', [
            'items_count' => count($dto->items),
        ]);
        
        $processedItems = [];
        
        foreach ($dto->items as $item) {
            $rawData = $item->rawData ?? [];
            
            // 1. Обработка специфичных полей SmartSmeta
            if (isset($rawData['kod_pozicii']) || isset($rawData['position_code'])) {
                $item->rawData['smartsmeta_position_code'] = $rawData['kod_pozicii'] ?? $rawData['position_code'];
            }
            
            // 2. Базовая и текущая цена
            if (isset($rawData['base_price']) || isset($rawData['bazovaya_cena'])) {
                $item->rawData['base_price'] = $rawData['base_price'] ?? $rawData['bazovaya_cena'];
            }
            
            if (isset($rawData['current_price']) || isset($rawData['tekushchaya_cena'])) {
                $item->rawData['current_price'] = $rawData['current_price'] ?? $rawData['tekushchaya_cena'];
            }
            
            // 3. Метод расчета
            $item->rawData['calculation_method'] = 'normative'; // SmartSmeta обычно работает с нормативами
            
            $processedItems[] = $item;
        }
        
        $dto->items = $processedItems;
        
        // Добавляем метаданные SmartSmeta
        $dto->metadata['estimate_type'] = 'smartsmeta';
        $dto->metadata['source_program'] = 'SmartSmeta';
        
        Log::info('[SmartSmetaAdapter] Adaptation completed', [
            'processed_items' => count($processedItems),
        ]);
        
        return $dto;
    }
    
    public function getSpecificFields(): array
    {
        return [
            'smartsmeta_position_code',  // Код позиции в SmartSmeta
            'base_price',                // Базовая цена
            'current_price',             // Текущая цена
        ];
    }
}

