<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Adapters;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\NormativeCodeService;
use Illuminate\Support\Facades\Log;

/**
 * Адаптер для ФЕР/ГЭСН смет
 * 
 * Специфика:
 * - Работа с кодами ФЕР, ГЭСН, ТЕР
 * - Обоснование в отдельной колонке
 * - Расценки из базы нормативов
 */
class FERAdapter implements EstimateAdapterInterface
{
    private NormativeCodeService $codeService;
    
    public function __construct()
    {
        $this->codeService = new NormativeCodeService();
    }
    
    public function supports(string $estimateType): bool
    {
        return $estimateType === 'fer';
    }
    
    public function adapt(EstimateImportDTO $dto, array $metadata): EstimateImportDTO
    {
        Log::info('[FERAdapter] Starting adaptation', [
            'items_count' => count($dto->items),
        ]);
        
        $processedItems = [];
        
        foreach ($dto->items as $item) {
            // Если item - это массив, преобразуем в EstimateImportRowDTO
            if (is_array($item)) {
                $item = new EstimateImportRowDTO(
                    rowNumber: $item['row_number'] ?? 0,
                    sectionNumber: $item['section_number'] ?? null,
                    itemName: $item['item_name'] ?? '',
                    unit: $item['unit'] ?? null,
                    quantity: $item['quantity'] ?? null,
                    unitPrice: $item['unit_price'] ?? null,
                    code: $item['code'] ?? null,
                    isSection: $item['is_section'] ?? false,
                    itemType: $item['item_type'] ?? 'work',
                    level: $item['level'] ?? 0,
                    sectionPath: $item['section_path'] ?? null,
                    rawData: $item['raw_data'] ?? null
                );
            }
            
            $rawData = $item->rawData ?? [];
            
            // 1. Нормализуем код норматива
            if ($item->code) {
                $extracted = $this->codeService->extractCode($item->code);
                
                if ($extracted) {
                    $item->rawData['normative_type'] = $extracted['type']; // FER, GESN, TER
                    $item->rawData['normative_code_normalized'] = $extracted['code'];
                    $item->rawData['normative_base'] = $extracted['base'] ?? null;
                }
            }
            
            // 2. Извлекаем обоснование (если есть отдельная колонка)
            if (isset($rawData['obosnovanie']) || isset($rawData['justification'])) {
                $justification = $rawData['obosnovanie'] ?? $rawData['justification'];
                $item->rawData['justification'] = $justification;
                
                // Пытаемся извлечь код из обоснования, если его нет
                if (empty($item->code)) {
                    $extracted = $this->codeService->extractCode($justification);
                    if ($extracted) {
                        $item->code = $extracted['code'];
                        $item->rawData['normative_type'] = $extracted['type'];
                    }
                }
            }
            
            // 3. Устанавливаем метод расчета
            $item->rawData['calculation_method'] = 'normative';
            
            $processedItems[] = $item;
        }
        
        $dto->items = $processedItems;
        
        // Добавляем метаданные ФЕР
        $dto->metadata['estimate_type'] = 'fer';
        $dto->metadata['calculation_method'] = 'normative';
        $dto->metadata['has_justification'] = true;
        
        Log::info('[FERAdapter] Adaptation completed', [
            'processed_items' => count($processedItems),
        ]);
        
        return $dto;
    }
    
    public function getSpecificFields(): array
    {
        return [
            'normative_type',              // Тип норматива: FER, GESN, TER
            'normative_code_normalized',   // Нормализованный код
            'normative_base',              // База норматива (например, "2001")
            'justification',               // Обоснование (текстовое поле)
        ];
    }
}

