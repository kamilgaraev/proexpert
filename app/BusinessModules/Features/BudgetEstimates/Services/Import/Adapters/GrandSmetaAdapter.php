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
                } elseif ($this->isMaterialOrEquipment($item)) {
                    // Это материал или оборудование верхнего уровня
                    $item->itemType = 'material'; 
                    
                    // Пробуем уточнить, оборудование ли это
                    // ГрандСмета может помечать оборудование в категории VidRab
                    // Но здесь простая эвристика по названию или коду
                    if (str_contains(mb_strtolower($item->itemName), 'оборудование') || 
                        str_contains(mb_strtolower($item->code), 'оборудование')) {
                        $item->itemType = 'equipment';
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
        // 1. Проверка по коду на наличие маркеров расценок (ГЭСН, ФЕР, ТЕР, ТСН)
        if ($item->code && preg_match('/^(ГЭСН|ФЕР|ТЕР|ТСН|GESN|FER|TER|TSN)/iu', $item->code)) {
            return true;
        }

        // 2. Проверка по наличию вложенных ресурсов (признак работы)
        // Если у позиции есть список ресурсов, это почти наверняка работа
        if (!empty($item->rawData['Resources'])) {
            return true;
        }

        // 3. Стандартная проверка по формату кода (01-01-001-1), если нет явного префикса
        if (!empty($item->code) && preg_match('/^\d{2}-\d{2}-\d{3}-\d{1,2}$/u', $item->code)) {
            return true;
        }
        
        return false;
    }

    /**
     * Определить, является ли позиция материалом или оборудованием верхнего уровня
     */
    private function isMaterialOrEquipment(EstimateImportRowDTO $item): bool
    {
        if (empty($item->code)) {
            return false;
        }

        // Маркеры материалов и оборудования
        if (preg_match('/^(ФССЦ|ФСБЦ|ТЦ|СЦ|ТССЦ|Ц|ТЦ_|Оборудование)/iu', $item->code)) {
            return true;
        }

        // Если это не работа (нет ресурсов) и есть цена, считаем материалом/оборудованием
        // Но нужно быть осторожным, чтобы не захватить разделы (они отфильтрованы ранее)
        if (empty($item->rawData['Resources']) && ($item->unitPrice > 0 || $item->currentTotalAmount > 0)) {
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

