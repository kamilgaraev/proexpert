<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Adapters;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use Illuminate\Support\Facades\Log;

/**
 * Универсальный адаптер для произвольных таблиц
 * 
 * КРИТИЧНО: Максимальная гибкость для разных структур
 * 
 * Поддерживает:
 * - Иерархические разделы (1.1, 1.2)
 * - Группировку по помещениям (Комната, Прихожая, Гостиная)
 * - Группировку по этапам (Демонтирование, Монтаж, Отделка)
 * - Вложенную структуру (Прихожая → Полы/Стены/Откосы)
 * - Плоскую структуру без разделов
 * - Разные названия колонок (синонимы)
 * - Промежуточные итоги ("Итого:", "За этап:")
 * - Позиции БЕЗ кодов нормативов
 */
class UniversalAdapter implements EstimateAdapterInterface
{
    public function supports(string $estimateType): bool
    {
        return $estimateType === 'custom' || $estimateType === 'universal';
    }
    
    public function adapt(EstimateImportDTO $dto, array $metadata): EstimateImportDTO
    {
        Log::info('[UniversalAdapter] Starting adaptation', [
            'items_count' => count($dto->items),
        ]);
        
        // 1. Определяем тип структуры разделов
        $structureType = $this->detectStructureType($dto->sections);
        
        Log::info('[UniversalAdapter] Structure detected', [
            'structure_type' => $structureType,
            'sections_count' => count($dto->sections),
        ]);
        
        // 2. Обрабатываем позиции
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
            
            // Пропускаем строки промежуточных итогов
            if ($this->isSubtotalRow($item)) {
                Log::debug('[UniversalAdapter] Skipping subtotal row', [
                    'name' => $item->name,
                ]);
                continue;
            }
            
            // Помечаем как ручные позиции (без нормативов)
            $item->rawData['is_manual'] = true;
            $item->rawData['calculation_method'] = 'manual';
            
            // 3. Гибкий маппинг колонок (синонимы)
            $item = $this->normalizeColumnNames($item);
            
            // 4. Определяем тип позиции (если не указан)
            if (empty($item->itemType)) {
                $item->itemType = 'work'; // По умолчанию - работа
            }
            
            $processedItems[] = $item;
        }
        
        $dto->items = $processedItems;
        
        // Добавляем метаданные произвольной таблицы
        $dto->metadata['estimate_type'] = 'custom';
        $dto->metadata['structure_type'] = $structureType;
        $dto->metadata['calculation_method'] = 'manual';
        $dto->metadata['requires_manual_review'] = true;
        
        Log::info('[UniversalAdapter] Adaptation completed', [
            'processed_items' => count($processedItems),
            'skipped_subtotals' => count($dto->items) - count($processedItems),
        ]);
        
        return $dto;
    }
    
    public function getSpecificFields(): array
    {
        return [
            'is_manual',           // Флаг ручной позиции (без норматива)
            'structure_type',      // Тип структуры: hierarchical, by_rooms, by_stages, flat, nested
            'requires_manual_review', // Требует ручной проверки
        ];
    }
    
    /**
     * Определить тип структуры разделов
     */
    private function detectStructureType(array $sections): string
    {
        if (empty($sections)) {
            return 'flat'; // Плоская структура без разделов
        }
        
        // Проверяем названия разделов
        $sectionNames = array_map(fn($s) => mb_strtolower($s['name'] ?? ''), $sections);
        $allNames = implode(' ', $sectionNames);
        
        // По помещениям
        $roomPatterns = ['комната', 'прихожая', 'гостиная', 'кухня', 'спальня', 'ванная'];
        foreach ($roomPatterns as $pattern) {
            if (str_contains($allNames, $pattern)) {
                return 'by_rooms';
            }
        }
        
        // По этапам работ
        $stagePatterns = ['демонтирование', 'монтаж', 'отделка', 'установка'];
        foreach ($stagePatterns as $pattern) {
            if (str_contains($allNames, $pattern)) {
                return 'by_stages';
            }
        }
        
        // Вложенная структура (по уровням)
        $maxLevel = max(array_map(fn($s) => $s['level'] ?? 1, $sections));
        if ($maxLevel >= 2) {
            // Проверяем характерные названия для вложенной структуры
            $nestedPatterns = ['полы', 'стены', 'потолки', 'откосы'];
            foreach ($nestedPatterns as $pattern) {
                if (str_contains($allNames, $pattern)) {
                    return 'nested';
                }
            }
        }
        
        // Иерархическая (с номерами 1.1, 1.2)
        foreach ($sectionNames as $name) {
            if (preg_match('/^\d+\.?\d*/', $name)) {
                return 'hierarchical';
            }
        }
        
        // По умолчанию - простая структура
        return 'simple';
    }
    
    /**
     * Проверить, является ли строка промежуточным итогом
     */
    private function isSubtotalRow(EstimateImportRowDTO $item): bool
    {
        $name = mb_strtolower($item->itemName ?? '');
        
        // Характерные паттерны итогов
        $subtotalPatterns = [
            'итого:',
            'за этап:',
            'всего:',
            'сумма:',
            'итого по разделу',
            'итого по этапу',
            'всего по смете',
        ];
        
        foreach ($subtotalPatterns as $pattern) {
            if (str_contains($name, $pattern)) {
                return true;
            }
        }
        
        // Строки, где в названии только "Итого" или "Всего"
        if (in_array(trim($name), ['итого', 'всего', 'сумма'])) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Нормализовать названия колонок (синонимы)
     */
    private function normalizeColumnNames(EstimateImportRowDTO $item): EstimateImportRowDTO
    {
        $rawData = $item->rawData ?? [];
        
        // Синонимы для количества
        $quantityKeys = ['kol_vo', 'kolvo', 'kolichestvo', 'obem', 'obyom'];
        foreach ($quantityKeys as $key) {
            if (isset($rawData[$key]) && empty($item->quantity)) {
                $item->quantity = $rawData[$key];
                break;
            }
        }
        
        // Синонимы для цены
        $priceKeys = ['cena', 'price', 'cena_za_ed', 'stoimost', 'summa'];
        foreach ($priceKeys as $key) {
            if (isset($rawData[$key]) && empty($item->unitPrice)) {
                $item->unitPrice = $rawData[$key];
                break;
            }
        }
        
        // Синонимы для единиц измерения
        $unitKeys = ['ed_izm', 'ed_izm_', 'edinica', 'unit'];
        foreach ($unitKeys as $key) {
            if (isset($rawData[$key]) && empty($item->unit)) {
                $item->unit = $rawData[$key];
                break;
            }
        }
        
        return $item;
    }
}

