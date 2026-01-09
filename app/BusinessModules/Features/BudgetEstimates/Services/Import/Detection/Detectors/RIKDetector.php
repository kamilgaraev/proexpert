<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\Detectors;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\EstimateTypeDetectorInterface;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Детектор РИК (Ресурсно-индексный метод)
 * 
 * Определяет сметы, составленные по ресурсно-индексному методу
 */
class RIKDetector implements EstimateTypeDetectorInterface
{
    public function detect($content): array
    {
        $indicators = [];
        $confidence = 0;
        
        // Если передан Spreadsheet, берем первый лист
        if ($content instanceof \PhpOffice\PhpSpreadsheet\Spreadsheet) {
            $content = $content->getSheet(0);
        }
        
        // 1. Характерные коды РИК: XX-XX-XXX-XX
        $rikCodes = $this->findRIKCodes($content);
        if (count($rikCodes) >= 5) {
            $indicators[] = 'rik_code_pattern_found';
            $confidence += 40;
            
            // Бонус за количество найденных кодов
            $confidence += min(count($rikCodes), 20);
        } elseif (count($rikCodes) >= 2) {
            $indicators[] = 'rik_code_pattern_partial';
            $confidence += 20;
        }
        
        // 2. Заголовок "РЕСУРСНО-ИНДЕКСНЫЙ"
        if ($this->hasKeyword($content, 'РЕСУРСНО-ИНДЕКСНЫЙ') || $this->hasKeyword($content, 'Ресурсно-индексный')) {
            $indicators[] = 'title_resursno_indexnyi';
            $confidence += 30;
        }
        
        // 3. Колонки с индексами
        if ($this->hasKeyword($content, 'Индекс к СМР') || $this->hasKeyword($content, 'Индекс СМР')) {
            $indicators[] = 'column_index_smr';
            $confidence += 15;
        }
        
        if ($this->hasKeyword($content, 'Индекс пересчета')) {
            $indicators[] = 'column_index_perescheta';
            $confidence += 10;
        }
        
        // 4. Характерные единицы РИК
        $rikUnits = ['чел.-ч', 'маш.-ч', 'чел-ч', 'маш-ч'];
        $foundUnits = 0;
        foreach ($rikUnits as $unit) {
            if ($this->hasKeyword($content, $unit)) {
                $foundUnits++;
            }
        }
        if ($foundUnits >= 2) {
            $indicators[] = 'rik_measurement_units';
            $confidence += 10;
        }
        
        // 5. Характерные термины РИК
        if ($this->hasKeyword($content, 'Ресурсная часть')) {
            $indicators[] = 'resource_part_term';
            $confidence += 5;
        }
        
        return [
            'confidence' => min($confidence, 100),
            'indicators' => $indicators,
        ];
    }
    
    public function getType(): string
    {
        return 'rik';
    }
    
    public function getDescription(): string
    {
        return 'РИК (Ресурсно-индексный метод)';
    }
    
    /**
     * Найти коды РИК в содержимом
     * 
     * @return array Найденные коды
     */
    private function findRIKCodes($content): array
    {
        $codes = [];
        $pattern = '/\d{2}-\d{2}-\d{3}-\d{2}/';
        
        if ($content instanceof Worksheet) {
            $maxRow = min(200, $content->getHighestRow());
            $highestCol = $content->getHighestColumn();
            
            for ($row = 1; $row <= $maxRow; $row++) {
                foreach (range('A', $highestCol) as $col) {
                    $value = (string)$content->getCell($col . $row)->getValue();
                    if (preg_match($pattern, $value, $matches)) {
                        $codes[] = $matches[0];
                    }
                }
            }
        } elseif (is_string($content)) {
            preg_match_all($pattern, $content, $matches);
            $codes = $matches[0] ?? [];
        }
        
        return array_unique($codes);
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
                foreach (range('A', $highestCol) as $col) {
                    $value = $content->getCell($col . $row)->getValue();
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

