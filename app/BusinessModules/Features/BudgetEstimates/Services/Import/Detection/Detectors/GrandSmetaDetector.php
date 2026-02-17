<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\Detectors;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\EstimateTypeDetectorInterface;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Детектор смет ГрандСмета
 * 
 * Определяет сметы, экспортированные из программы ГрандСмета
 * (в Excel или XML формате)
 */
class GrandSmetaDetector implements EstimateTypeDetectorInterface
{
    public function detect($content): array
    {
        $indicators = [];
        $confidence = 0;
        
        // Если передан Spreadsheet, берем первый лист
        if ($content instanceof \PhpOffice\PhpSpreadsheet\Spreadsheet) {
            $content = $content->getSheet(0);
        }
        
        // Проверяем наличие характерных заголовков и структуры ГрандСметы
        
        // 1. Ищем характерный заголовок "ЛОКАЛЬНЫЙ СМЕТНЫЙ РАСЧЕТ"
        if ($this->hasKeyword($content, 'ЛОКАЛЬНЫЙ СМЕТНЫЙ РАСЧЕТ')) {
            $indicators[] = 'title_localnyi_smetnyi_raschet';
            $confidence += 30;
        }
        
        // 2. Ищем упоминание программы "Гранд-Смета" или "GRAND-SMETA"
        if ($this->hasKeyword($content, 'Гранд-Смета') || $this->hasKeyword($content, 'GRAND-SMETA') || $this->hasKeyword($content, 'GrandSmeta')) {
            $indicators[] = 'program_name_grandsmeta';
            $confidence += 25;
        }
        
        // 3. Проверяем структуру колонок (характерные для ГрандСметы)
        $columnPatterns = [
            'Обоснование',
            'Наименование',
            'Базисном уровне',
            'Текущем уровне',
            'Всего по разделу',
        ];
        
        $foundColumns = 0;
        foreach ($columnPatterns as $pattern) {
            if ($this->hasKeyword($content, $pattern)) {
                $foundColumns++;
            }
        }
        
        if ($foundColumns >= 3) {
            $indicators[] = 'column_pattern_grandsmeta';
            $confidence += 20 + ($foundColumns * 2); // Бонус за каждую найденную колонку
        }
        
        // 4. Проверяем разделение на ОТ/ЭМ/М (характерно для ГрандСметы)
        if ($this->hasResourceBreakdown($content)) {
            $indicators[] = 'resource_breakdown_ot_em_m';
            $confidence += 15;
        }
        
        // 5. Характерные служебные строки ГрандСметы
        if ($this->hasKeyword($content, 'в текущих ценах')) {
            $indicators[] = 'current_prices_wording';
            $confidence += 5;
        }
        
        if ($this->hasKeyword($content, 'Всего по смете')) {
            $indicators[] = 'total_by_estimate';
            $confidence += 5;
        }
        
        // 6. Структура "Приложение к договору"
        if ($this->hasKeyword($content, 'Приложение к договору')) {
            $indicators[] = 'attachment_to_contract';
            $confidence += 3;
        }
        
        return [
            'confidence' => min($confidence, 100),
            'indicators' => $indicators,
        ];
    }
    
    public function getType(): string
    {
        return 'grandsmeta';
    }
    
    public function getDescription(): string
    {
        return 'ГрандСмета (экспорт из программы)';
    }
    
    /**
     * Проверить наличие ключевого слова в содержимом
     */
    private function hasKeyword($content, string $keyword): bool
    {
        if ($content instanceof Worksheet) {
            // Поиск в Excel (первые 50 строк для производительности)
            $maxRow = min(50, $content->getHighestRow());
            $highestCol = $content->getHighestColumn();
            
            for ($row = 1; $row <= $maxRow; $row++) {
                $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
                
                for ($colIdx = 1; $colIdx <= $highestColIndex; $colIdx++) {
                    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
                    $cell = $content->getCell($col . $row);
                    if ($value && str_contains(mb_strtolower((string)$value), mb_strtolower($keyword))) {
                        return true;
                    }
                }
            }
        } elseif (is_string($content)) {
            // Поиск в XML или текстовом содержимом
            return str_contains(mb_strtolower($content), mb_strtolower($keyword));
        } elseif ($content instanceof \SimpleXMLElement) {
            // Поиск в XML
            $xmlString = $content->asXML();
            return str_contains(mb_strtolower($xmlString), mb_strtolower($keyword));
        }
        
        return false;
    }
    
    /**
     * Проверить наличие разделения на ОТ/ЭМ/М
     */
    private function hasResourceBreakdown($content): bool
    {
        // Ищем строки с "ОТ(ЗТ)", "ЭМ", "М", "ОТм(ЗТм)"
        $patterns = ['ОТ(ЗТ)', 'ЭМ', 'М', 'ОТм(ЗТм)', 'ОТ (ЗТ)', 'ОТм (ЗТм)'];
        $found = 0;
        
        foreach ($patterns as $pattern) {
            if ($this->hasKeyword($content, $pattern)) {
                $found++;
            }
        }
        
        // Минимум 2 из 4 паттернов
        return $found >= 2;
    }
}

