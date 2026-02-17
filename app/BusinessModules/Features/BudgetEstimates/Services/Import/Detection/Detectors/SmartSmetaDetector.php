<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\Detectors;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\EstimateTypeDetectorInterface;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Детектор SmartSmeta
 * 
 * Определяет сметы из программы Smeta.ru / SmartSmeta
 */
class SmartSmetaDetector implements EstimateTypeDetectorInterface
{
    public function detect($content): array
    {
        $indicators = [];
        $confidence = 0;
        
        // Если передан Spreadsheet, берем первый лист
        if ($content instanceof \PhpOffice\PhpSpreadsheet\Spreadsheet) {
            $content = $content->getSheet(0);
        }
        
        // 1. Ищем маркеры Smeta.ru
        if ($this->hasKeyword($content, 'Smeta.ru') || $this->hasKeyword($content, 'smeta.ru')) {
            $indicators[] = 'program_name_smeta_ru';
            $confidence += 40;
        }
        
        if ($this->hasKeyword($content, 'SmartSmeta') || $this->hasKeyword($content, 'Smart Smeta')) {
            $indicators[] = 'program_name_smartsmeta';
            $confidence += 40;
        }
        
        // 2. Характерные термины SmartSmeta
        if ($this->hasKeyword($content, 'Смарт-Смета') || $this->hasKeyword($content, 'СмартСмета')) {
            $indicators[] = 'program_name_smartsmeta_ru';
            $confidence += 35;
        }
        
        // 3. Специфичная структура колонок SmartSmeta
        $columnPatterns = [
            'Код позиции',
            'Описание позиции',
            'Базовая цена',
            'Текущая цена',
        ];
        
        $foundColumns = 0;
        foreach ($columnPatterns as $pattern) {
            if ($this->hasKeyword($content, $pattern)) {
                $foundColumns++;
            }
        }
        
        if ($foundColumns >= 2) {
            $indicators[] = 'column_pattern_smartsmeta';
            $confidence += 15 + ($foundColumns * 5);
        }
        
        // 4. Характерные служебные строки
        if ($this->hasKeyword($content, 'Сформирована в программе Smeta.ru')) {
            $indicators[] = 'generated_by_smeta_ru';
            $confidence += 20;
        }
        
        return [
            'confidence' => min($confidence, 100),
            'indicators' => $indicators,
        ];
    }
    
    public function getType(): string
    {
        return 'smartsmeta';
    }
    
    public function getDescription(): string
    {
        return 'SmartSmeta / Smeta.ru';
    }
    
    /**
     * Проверить наличие ключевого слова в содержимом
     */
    private function hasKeyword($content, string $keyword): bool
    {
        if ($content instanceof Worksheet) {
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
            return str_contains(mb_strtolower($content), mb_strtolower($keyword));
        }
        
        return false;
    }
}

