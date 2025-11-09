<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\Detectors;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\EstimateTypeDetectorInterface;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\NormativeCodeService;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Детектор произвольных таблиц
 * 
 * Fallback-детектор для смет, составленных в произвольной форме
 * (без официальных кодов нормативов)
 * 
 * КРИТИЧНО: Должен работать с огромным разнообразием структур:
 * - Группировка по комнатам/помещениям
 * - Группировка по этапам работ
 * - Вложенные разделы
 * - Разные названия колонок
 * - Промежуточные итоги в любых местах
 */
class CustomTableDetector implements EstimateTypeDetectorInterface
{
    private NormativeCodeService $codeService;
    
    public function __construct()
    {
        $this->codeService = new NormativeCodeService();
    }
    
    public function detect($content): array
    {
        $indicators = [];
        $confidence = 30; // Базовый confidence (низкий, т.к. это fallback)
        
        // 1. КЛЮЧЕВОЙ ПРИЗНАК: Отсутствие кодов нормативов
        $hasNormativeCodes = $this->hasNormativeCodes($content);
        
        if (!$hasNormativeCodes) {
            $indicators[] = 'no_normative_codes';
            $confidence += 20; // Повышаем confidence если нет кодов
        } else {
            // Если есть коды нормативов - это точно не произвольная таблица
            $indicators[] = 'has_normative_codes';
            return [
                'confidence' => 0, // Нулевой confidence
                'indicators' => $indicators,
            ];
        }
        
        // 2. Проверка базовой структуры таблицы
        $hasWorkNames = $this->hasWorkNamesColumn($content);
        $hasQuantity = $this->hasQuantityColumn($content);
        $hasPrice = $this->hasPriceColumn($content);
        
        if ($hasWorkNames) {
            $indicators[] = 'has_work_names_column';
            $confidence += 15;
        }
        
        if ($hasQuantity) {
            $indicators[] = 'has_quantity_column';
            $confidence += 10;
        }
        
        if ($hasPrice) {
            $indicators[] = 'has_price_column';
            $confidence += 10;
        }
        
        // Если есть все 3 основные колонки - это точно таблица смет
        if ($hasWorkNames && $hasQuantity && $hasPrice) {
            $indicators[] = 'complete_table_structure';
            $confidence += 15;
        }
        
        // 3. Проверка наличия единиц измерения
        if ($this->hasUnitsColumn($content)) {
            $indicators[] = 'has_units_column';
            $confidence += 5;
        }
        
        // 4. Проверка признаков разделов/группировки
        $sectionPatterns = $this->detectSectionPatterns($content);
        
        if (!empty($sectionPatterns)) {
            $indicators[] = 'has_section_structure';
            $indicators = array_merge($indicators, $sectionPatterns);
            $confidence += count($sectionPatterns) * 3;
        }
        
        // 5. Проверка промежуточных итогов
        if ($this->hasSubtotals($content)) {
            $indicators[] = 'has_subtotals';
            $confidence += 5;
        }
        
        return [
            'confidence' => min($confidence, 100),
            'indicators' => $indicators,
        ];
    }
    
    public function getType(): string
    {
        return 'custom';
    }
    
    public function getDescription(): string
    {
        return 'Произвольная таблица (без официальных кодов)';
    }
    
    /**
     * Проверить наличие кодов нормативов в содержимом
     */
    private function hasNormativeCodes($content): bool
    {
        if ($content instanceof Worksheet) {
            $maxRow = min(100, $content->getHighestRow());
            $highestCol = $content->getHighestColumn();
            $codesFound = 0;
            
            for ($row = 1; $row <= $maxRow; $row++) {
                foreach (range('A', $highestCol) as $col) {
                    $value = (string)$content->getCell($col . $row)->getValue();
                    
                    if (empty($value)) {
                        continue;
                    }
                    
                    // Проверяем на наличие валидного кода
                    if ($this->codeService->isValidCode($value) && !$this->codeService->isPseudoCode($value)) {
                        $codesFound++;
                        
                        // Если найдено >= 3 валидных кодов - это не произвольная таблица
                        if ($codesFound >= 3) {
                            return true;
                        }
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Проверить наличие колонки с названиями работ
     */
    private function hasWorkNamesColumn($content): bool
    {
        $patterns = [
            'Наименование работ',
            'Наименование',
            'Виды работ',
            'Работы',
            'Название',
            'Описание работ',
        ];
        
        foreach ($patterns as $pattern) {
            if ($this->hasKeyword($content, $pattern)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Проверить наличие колонки с количеством
     */
    private function hasQuantityColumn($content): bool
    {
        $patterns = [
            'Количество',
            'Кол-во',
            'Кол.во',
            'Объем',
            'Объём',
            'Кол.',
        ];
        
        foreach ($patterns as $pattern) {
            if ($this->hasKeyword($content, $pattern)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Проверить наличие колонки с ценой/стоимостью
     */
    private function hasPriceColumn($content): bool
    {
        $patterns = [
            'Цена',
            'Стоимость',
            'Цена за ед',
            'Сумма',
            'Сумма в руб',
            'Итого',
            'Сметная стоимость',
        ];
        
        foreach ($patterns as $pattern) {
            if ($this->hasKeyword($content, $pattern)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Проверить наличие колонки с единицами измерения
     */
    private function hasUnitsColumn($content): bool
    {
        $patterns = [
            'Ед.изм',
            'Ед. изм',
            'Ед.изм.',
            'Единица',
            'Единица измерения',
        ];
        
        foreach ($patterns as $pattern) {
            if ($this->hasKeyword($content, $pattern)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Определить паттерны разделов
     */
    private function detectSectionPatterns($content): array
    {
        $patterns = [];
        
        // По этапам работ
        $stagePatterns = ['Демонтирование', 'Монтаж', 'Отделка', 'Установка'];
        foreach ($stagePatterns as $pattern) {
            if ($this->hasKeyword($content, $pattern)) {
                $patterns[] = 'sections_by_stages';
                break;
            }
        }
        
        // По помещениям
        $roomPatterns = ['Комната', 'Прихожая', 'Гостиная', 'Кухня', 'Спальня', 'Ванная'];
        foreach ($roomPatterns as $pattern) {
            if ($this->hasKeyword($content, $pattern)) {
                $patterns[] = 'sections_by_rooms';
                break;
            }
        }
        
        // Вложенная структура (Полы, Стены, Потолки)
        $nestedPatterns = ['Полы', 'Стены', 'Потолки', 'Потолок', 'Откосы'];
        $found = 0;
        foreach ($nestedPatterns as $pattern) {
            if ($this->hasKeyword($content, $pattern)) {
                $found++;
            }
        }
        if ($found >= 2) {
            $patterns[] = 'sections_nested';
        }
        
        // Разделы с номерами
        if ($this->hasKeyword($content, 'Раздел') || $this->hasKeyword($content, 'Глава') || $this->hasKeyword($content, 'Этап')) {
            $patterns[] = 'sections_numbered';
        }
        
        return array_unique($patterns);
    }
    
    /**
     * Проверить наличие промежуточных итогов
     */
    private function hasSubtotals($content): bool
    {
        $patterns = ['Итого', 'За этап', 'Всего', 'Сумма'];
        
        foreach ($patterns as $pattern) {
            if ($this->hasKeyword($content, $pattern . ':')) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Проверить наличие ключевого слова в содержимом
     */
    private function hasKeyword($content, string $keyword): bool
    {
        if ($content instanceof Worksheet) {
            $maxRow = min(100, $content->getHighestRow());
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

