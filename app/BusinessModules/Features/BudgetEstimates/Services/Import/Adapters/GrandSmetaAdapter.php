<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Adapters;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use Illuminate\Support\Facades\Log;

/**
 * Адаптер для смет ГрандСмета
 * 
 * Специфика:
 * - Разделение на подпозиции ОТ (ЗТ), ЭМ, М, ОТм (ЗТм)
 * - Базисный и текущий уровень цен
 * - Коэффициенты пересчета
 * - Связь ресурсов с родительской работой (parent_work_id)
 */
class GrandSmetaAdapter implements EstimateAdapterInterface
{
    public function supports(string $estimateType): bool
    {
        return $estimateType === 'grandsmeta';
    }
    
    public function adapt(EstimateImportDTO $dto, array $metadata): EstimateImportDTO
    {
        Log::info('[GrandSmetaAdapter] Starting adaptation', [
            'items_count' => count($dto->items),
        ]);
        
        $currentWorkId = null;
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
            
            $itemType = $item->itemType ?? 'work';
            $rawData = $item->rawData ?? [];
            
            // 1. Определить, является ли строка основной работой (ГЭСН)
            $isMainWork = $this->isMainWork($item);
            
            if ($isMainWork) {
                // Это основная работа - запоминаем ее ID для привязки ресурсов
                $currentWorkId = $item->code;
                
                // Обрабатываем основную работу
                $item->rawData['is_main_work'] = true;
                $item->rawData['calculation_method'] = 'normative'; // ГрандСмета использует нормативы
                
                $processedItems[] = $item;
            } else {
                // 2. Проверить, является ли строка ресурсом (ОТ/ЭМ/М)
                $resourceType = $this->detectResourceType($item);
                
                if ($resourceType) {
                    // Это ресурс - привязываем к текущей работе
                    $item->itemType = $resourceType;
                    $item->rawData['parent_work_code'] = $currentWorkId;
                    $item->rawData['is_resource'] = true;
                    
                    // Извлекаем базисный и текущий уровень цен
                    if (isset($rawData['base_price'])) {
                        $item->rawData['base_price'] = $rawData['base_price'];
                    }
                    
                    if (isset($rawData['current_price'])) {
                        $item->rawData['current_price'] = $rawData['current_price'];
                    }
                    
                    $processedItems[] = $item;
                } else {
                    // Обычная позиция или итог
                    $processedItems[] = $item;
                }
            }
        }
        
        $dto->items = $processedItems;
        
        // Добавляем метаданные специфичные для ГрандСметы
        $dto->metadata['estimate_type'] = 'grandsmeta';
        $dto->metadata['has_resource_breakdown'] = true;
        $dto->metadata['price_levels'] = ['base', 'current'];
        
        Log::info('[GrandSmetaAdapter] Adaptation completed', [
            'processed_items' => count($processedItems),
        ]);
        
        return $dto;
    }
    
    public function getSpecificFields(): array
    {
        return [
            'base_price',              // Базисная цена
            'current_price',           // Текущая цена
            'coefficient',             // Коэффициент пересчета
            'parent_work_code',        // Код родительской работы для ресурсов
            'resource_type',           // Тип ресурса: ot, em, m, otm
            'is_main_work',            // Флаг основной работы
            'is_resource',             // Флаг ресурса
        ];
    }
    
    /**
     * Определить, является ли позиция основной работой (ГЭСН)
     */
    private function isMainWork(EstimateImportRowDTO $item): bool
    {
        // Основная работа имеет код ГЭСН и не является ресурсом
        if (empty($item->code)) {
            return false;
        }
        
        // Проверяем, что код похож на ГЭСН (например, 01-01-001-1)
        if (preg_match('/^\d{2}-\d{2}-\d{3}-\d{1,2}$/u', $item->code)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Определить тип ресурса (ОТ/ЭМ/М/ОТм)
     */
    private function detectResourceType(EstimateImportRowDTO $item): ?string
    {
        $name = mb_strtolower($item->itemName ?? '');
        $code = mb_strtolower($item->code ?? '');
        
        // Проверка по коду или названию
        if (str_contains($name, 'от(зт)') || str_contains($code, 'от(зт)') || 
            str_contains($name, 'от (зт)') || str_contains($code, 'от (зт)')) {
            return 'labor'; // Трудозатраты
        }
        
        if (str_contains($name, 'эм') || str_contains($code, 'эм')) {
            return 'machinery'; // Эксплуатация машин
        }
        
        if (str_contains($name, 'отм(зтм)') || str_contains($code, 'отм(зтм)') ||
            str_contains($name, 'отм (зтм)') || str_contains($code, 'отм (зтм)')) {
            return 'machinery_labor'; // Трудозатраты машинистов
        }
        
        if (preg_match('/^м\s/u', $name) || preg_match('/^м$/u', $code)) {
            return 'material'; // Материалы
        }
        
        return null;
    }
}

