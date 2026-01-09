<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\Detectors;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\EstimateTypeDetectorInterface;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\NormativeCodeService;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Детектор ФЕР/ГЭСН смет
 * 
 * Определяет сметы на основе федеральных единичных расценок (ФЕР)
 * или государственных элементных сметных норм (ГЭСН)
 */
class FERDetector implements EstimateTypeDetectorInterface
{
    private NormativeCodeService $codeService;
    
    public function __construct()
    {
        $this->codeService = new NormativeCodeService();
    }
    
    public function detect($content): array
    {
        $indicators = [];
        $confidence = 0;
        
        // Если передан Spreadsheet, берем первый лист
        if ($content instanceof \PhpOffice\PhpSpreadsheet\Spreadsheet) {
            $content = $content->getSheet(0);
        }
        
        // 1. Ищем коды ФЕР/ГЭСН/ТЕР в содержимом
        $codes = $this->findNormativeCodes($content);
        
        if (count($codes['fer']) >= 5) {
            $indicators[] = 'fer_codes_found';
            $confidence += 35;
        } elseif (count($codes['fer']) >= 2) {
            $indicators[] = 'fer_codes_partial';
            $confidence += 20;
        }
        
        if (count($codes['gesn']) >= 5) {
            $indicators[] = 'gesn_codes_found';
            $confidence += 35;
        } elseif (count($codes['gesn']) >= 2) {
            $indicators[] = 'gesn_codes_partial';
            $confidence += 20;
        }
        
        if (count($codes['ter']) >= 3) {
            $indicators[] = 'ter_codes_found';
            $confidence += 25;
        }
        
        // 2. Проверяем наличие колонки "Обоснование"
        if ($this->hasKeyword($content, 'Обоснование')) {
            $indicators[] = 'column_obosnovanie';
            $confidence += 15;
        }
        
        // 3. Характерные термины ФЕР
        if ($this->hasKeyword($content, 'Федеральные единичные расценки') || $this->hasKeyword($content, 'ФЕР')) {
            $indicators[] = 'title_fer';
            $confidence += 20;
        }
        
        if ($this->hasKeyword($content, 'ГЭСН')) {
            $indicators[] = 'title_gesn';
            $confidence += 15;
        }
        
        if ($this->hasKeyword($content, 'ТЕР')) {
            $indicators[] = 'title_ter';
            $confidence += 10;
        }
        
        // 4. Проверка на колонку с расценками
        if ($this->hasKeyword($content, 'Расценка') || $this->hasKeyword($content, 'Шифр расценки')) {
            $indicators[] = 'column_rascenka';
            $confidence += 10;
        }
        
        return [
            'confidence' => min($confidence, 100),
            'indicators' => $indicators,
        ];
    }
    
    public function getType(): string
    {
        return 'fer';
    }
    
    public function getDescription(): string
    {
        return 'ФЕР/ГЭСН (Федеральные/Государственные расценки)';
    }
    
    /**
     * Найти нормативные коды в содержимом
     * 
     * @return array ['fer' => array, 'gesn' => array, 'ter' => array]
     */
    private function findNormativeCodes($content): array
    {
        $codes = [
            'fer' => [],
            'gesn' => [],
            'ter' => [],
        ];
        
        if ($content instanceof Worksheet) {
            $maxRow = min(200, $content->getHighestRow());
            $highestCol = $content->getHighestColumn();
            
            for ($row = 1; $row <= $maxRow; $row++) {
                foreach (range('A', $highestCol) as $col) {
                    $value = (string)$content->getCell($col . $row)->getValue();
                    
                    if (empty($value)) {
                        continue;
                    }
                    
                    $extracted = $this->codeService->extractCode($value);
                    
                    if ($extracted) {
                        $type = $extracted['type'];
                        
                        if ($type === 'FER') {
                            $codes['fer'][] = $extracted['code'];
                        } elseif ($type === 'GESN' || $type === 'GESN_TECH') {
                            $codes['gesn'][] = $extracted['code'];
                        } elseif ($type === 'TER') {
                            $codes['ter'][] = $extracted['code'];
                        }
                    }
                }
            }
        } elseif (is_string($content)) {
            // Разбиваем на строки
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                $extracted = $this->codeService->extractCode($line);
                
                if ($extracted) {
                    $type = $extracted['type'];
                    
                    if ($type === 'FER') {
                        $codes['fer'][] = $extracted['code'];
                    } elseif ($type === 'GESN' || $type === 'GESN_TECH') {
                        $codes['gesn'][] = $extracted['code'];
                    } elseif ($type === 'TER') {
                        $codes['ter'][] = $extracted['code'];
                    }
                }
            }
        }
        
        // Убираем дубликаты
        $codes['fer'] = array_unique($codes['fer']);
        $codes['gesn'] = array_unique($codes['gesn']);
        $codes['ter'] = array_unique($codes['ter']);
        
        return $codes;
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

