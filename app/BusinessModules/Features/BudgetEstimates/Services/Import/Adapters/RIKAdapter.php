<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Adapters;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use Illuminate\Support\Facades\Log;

/**
 * Адаптер для РИК (Ресурсно-индексный метод)
 * 
 * Специфика:
 * - Индексы СМР и пересчета
 * - Ресурсная часть с детализацией
 * - Коды формата XX-XX-XXX-XX
 * - Трудозатраты в чел.-ч, маш.-ч
 */
class RIKAdapter implements EstimateAdapterInterface
{
    public function supports(string $estimateType): bool
    {
        return $estimateType === 'rik';
    }
    
    public function adapt(EstimateImportDTO $dto, array $metadata): EstimateImportDTO
    {
        Log::info('[RIKAdapter] Starting adaptation', [
            'items_count' => count($dto->items),
        ]);
        
        $processedItems = [];
        
        foreach ($dto->items as $item) {
            $rawData = $item->rawData ?? [];
            
            // 1. Извлекаем индексы
            if (isset($rawData['index_smr'])) {
                $item->rawData['index_smr'] = $this->parseFloat($rawData['index_smr']);
            }
            
            if (isset($rawData['index_perescheta'])) {
                $item->rawData['index_perescheta'] = $this->parseFloat($rawData['index_perescheta']);
            }
            
            // 2. Определяем тип позиции по единицам измерения
            if (isset($item->unit)) {
                $unit = mb_strtolower($item->unit);
                
                if (str_contains($unit, 'чел.-ч') || str_contains($unit, 'чел-ч')) {
                    $item->itemType = 'labor';
                    $item->rawData['unit_type'] = 'labor_hours';
                } elseif (str_contains($unit, 'маш.-ч') || str_contains($unit, 'маш-ч')) {
                    $item->itemType = 'machinery';
                    $item->rawData['unit_type'] = 'machine_hours';
                }
            }
            
            // 3. Нормализуем код РИК
            if ($item->code && $this->isRIKCode($item->code)) {
                $item->rawData['rik_code'] = $item->code;
                $item->rawData['calculation_method'] = 'resource_index';
            }
            
            $processedItems[] = $item;
        }
        
        $dto->items = $processedItems;
        
        // Добавляем метаданные РИК
        $dto->metadata['estimate_type'] = 'rik';
        $dto->metadata['calculation_method'] = 'resource_index';
        $dto->metadata['has_indexes'] = true;
        
        Log::info('[RIKAdapter] Adaptation completed', [
            'processed_items' => count($processedItems),
        ]);
        
        return $dto;
    }
    
    public function getSpecificFields(): array
    {
        return [
            'index_smr',           // Индекс к СМР
            'index_perescheta',    // Индекс пересчета
            'rik_code',            // Код РИК формата XX-XX-XXX-XX
            'unit_type',           // Тип единицы: labor_hours, machine_hours
        ];
    }
    
    /**
     * Проверить, является ли код кодом РИК
     */
    private function isRIKCode(string $code): bool
    {
        return (bool) preg_match('/^\d{2}-\d{2}-\d{3}-\d{2}$/', $code);
    }
    
    /**
     * Парсинг числа с запятой/точкой
     */
    private function parseFloat(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        
        if (is_string($value)) {
            $cleaned = str_replace([' ', ','], ['', '.'], $value);
            return is_numeric($cleaned) ? (float) $cleaned : null;
        }
        
        return null;
    }
}

