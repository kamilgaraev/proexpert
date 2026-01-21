<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\Detectors;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Detection\EstimateTypeDetectorInterface;
use SimpleXMLElement;

/**
 * Детектор XML смет
 * 
 * Определяет, является ли файл XML-сметой (ГрандСмета, GGE, или другие форматы)
 * путем анализа XML структуры
 */
class XmlEstimateDetector implements EstimateTypeDetectorInterface
{
    private string $detectedType = 'xml_estimate';
    private string $description = 'XML Смета (ГрандСмета, GGE или совместимый формат)';

    public function detect($content): array
    {
        // SAFETY CHECK: Если контент не строка и не SimpleXMLElement (например, Spreadsheet объект),
        // то этот детектор не применим.
        if (empty($content) || (!is_string($content) && !($content instanceof SimpleXMLElement))) {
             return [
                'confidence' => 0,
                'indicators' => ['Invalid content type or empty'],
            ];
        }

        $indicators = [];
        $confidence = 0;
        
        $xml = null;
        
        // 1. Пытаемся получить XML объект или анализируем строку
        if ($content instanceof SimpleXMLElement) {
            $xml = $content;
        } elseif (is_string($content)) {
            // Очистка и подготовка содержимого
            // 1. Убираем BOM и пробелы в начале
            $content = trim($content);
            $bom = pack('H*','EFBBBF');
            $content = preg_replace("/^$bom/", '', $content);
            
            // Если после удаления BOM строка пустая - выходим
            if (empty($content)) {
                 return [
                    'confidence' => 0,
                    'indicators' => ['Empty content after cleanup'],
                ];
            }
            
            // 2. Исправляем кодировку (Windows-1251 -> UTF-8), если не объявлена
            // Часто бывает <?xml version="1.0" ... без encoding, но внутри 1251
            // Используем str_contains вместо preg_match для проверки заголовка, чтобы избежать проблем с кодировкой в regex
            $hasEncoding = str_contains($content, 'encoding=');
            
            if (!$hasEncoding) {
                 if (!mb_check_encoding($content, 'UTF-8')) {
                     // Пытаемся сконвертировать из 1251 в UTF-8 перед любыми операциями
                     try {
                        $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
                     } catch (\Throwable $e) {
                         // Если конвертация не удалась, оставляем как есть
                     }
                     
                     // Добавляем объявление
                     if (!str_contains($content, '<?xml')) {
                         $content = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . $content;
                     } else {
                         // Заменяем заголовок на UTF-8 (так как мы сконвертировали контент)
                         $content = str_replace('<?xml version="1.0"?>', '<?xml version="1.0" encoding="utf-8"?>', $content);
                     }
                 }
            }

            // 3. Санитизация невалидных символов (теперь безопасна, так как мы постарались привести к UTF-8)
            $sanitized = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $content);
            
            if ($sanitized !== null) {
                $content = $sanitized;
            } else {
                // Если preg_replace вернул null (ошибка кодировки), попробуем почистить другим способом или оставим как есть
                // для fallback regex
            }

            // Проверка на XML заголовок или теги
            if (str_contains($content, '<?xml') || (str_contains($content, '<') && str_contains($content, '>'))) {
                try {
                    // Подавляем ошибки парсинга
                    libxml_use_internal_errors(true);
                    $xml = simplexml_load_string($content);
                    libxml_clear_errors();
                } catch (\Exception $e) {
                    // Не валидный XML
                }
            }

            // FALLBACK: Если simplexml не справился, используем Regex для детекции
            // Это решает проблему "битых" файлов или странных кодировок, которые ломают парсер, 
            // но текст в них читаем.
            if (!$xml) {
                // Ищем ключевые маркеры ГрандСметы или XML сметы
                $regexConfidence = 0;
                $regexIndicators = [];
                
                if (preg_match('/<GrandSmeta/i', $content) || preg_match('/Generator="GrandSmeta"/i', $content)) {
                    $regexConfidence = 100;
                    $regexIndicators[] = 'regex_match_grandsmeta';
                    $this->detectedType = 'grandsmeta';
                    $this->description = 'ГрандСмета (экспорт из программы)';
                } elseif (preg_match('/<Estimate/i', $content) || preg_match('/<LocalEstimate/i', $content)) {
                     $regexConfidence = 80;
                     $regexIndicators[] = 'regex_match_estimate';
                     $this->detectedType = 'xml_estimate';
                }
                
                if ($regexConfidence > 0) {
                     return [
                        'confidence' => $regexConfidence,
                        'indicators' => array_merge(['valid_xml_structure_failed_but_regex_passed'], $regexIndicators),
                    ];
                }
            }
        }
        
        if (!$xml) {
            return [
                'confidence' => 0,
                'indicators' => ['Не является валидным XML'],
            ];
        }
        
        $indicators[] = 'valid_xml_structure';
        $confidence += 20;
        
        // 2. Анализ корневого элемента
        $rootName = $xml->getName();
        $estimateRoots = ['GrandSmeta', 'Estimate', 'LocalEstimate', 'ObjectEstimate', 'GGE', 'Smeta', 'LSR', 'Document'];
        
        foreach ($estimateRoots as $root) {
            if (strcasecmp($rootName, $root) === 0) {
                $indicators[] = "root_element_{$rootName}";
                $confidence += 40;
                
                // Если корень явно GrandSmeta, меняем тип
                if (strcasecmp($rootName, 'GrandSmeta') === 0) {
                    $this->detectedType = 'grandsmeta';
                    $this->description = 'ГрандСмета (экспорт из программы)';
                }
                break;
            }
        }

        // Проверка атрибута Generator
        $generator = (string)($xml['Generator'] ?? '');
        if (!empty($generator)) {
            $indicators[] = "generator_{$generator}";
            // Если генератор известен (GrandSmeta, Smeta.ru и т.д.), повышаем уверенность
            if (mb_stripos($generator, 'GrandSmeta') !== false) {
                $confidence += 30;
                $this->detectedType = 'grandsmeta';
                $this->description = 'ГрандСмета (экспорт из программы)';
            } elseif (mb_stripos($generator, 'Smeta') !== false) {
                $confidence += 30;
                $this->detectedType = 'smartsmeta';
                $this->description = 'SmartSmeta / Smeta.ru';
            } elseif (mb_stripos($generator, 'GGE') !== false) {
                $confidence += 30;
                // GGE обычно обрабатывается как generic XML или имеет свой формат, оставим xml_estimate или добавим gge если нужно
            } else {
                $confidence += 10;
            }
        }
        
        // 3. Поиск ключевых узлов внутри
        $hasSections = false;
        $hasItems = false;
        
        // Ищем разделы
        $sectionKeywords = ['Section', 'Razdel', 'Chapter', 'Part'];
        foreach ($xml->children() as $child) {
            foreach ($sectionKeywords as $keyword) {
                if (mb_stripos($child->getName(), $keyword) !== false) {
                    $hasSections = true;
                    break 2;
                }
            }
        }
        
        if ($hasSections) {
            $indicators[] = 'has_sections';
            $confidence += 20;
        }
        
        // Ищем позиции (могут быть внутри разделов, поэтому рекурсивно не ищем для скорости, 
        // но если корневая структура плоская, найдем)
        $itemKeywords = ['Position', 'Item', 'Poz', 'Line', 'Row'];
        // Проверяем детей корня и детей первого уровня (если есть разделы)
        $nodesToCheck = [$xml];
        if ($xml->count() > 0) {
            foreach($xml->children() as $child) {
                $nodesToCheck[] = $child;
            }
        }
        
        foreach ($nodesToCheck as $node) {
            foreach ($node->children() as $child) {
                 foreach ($itemKeywords as $keyword) {
                    if (mb_stripos($child->getName(), $keyword) !== false) {
                        $hasItems = true;
                        break 3;
                    }
                }
            }
        }
        
        if ($hasItems) {
            $indicators[] = 'has_items';
            $confidence += 20;
        }
        
        // 4. Проверка атрибутов, характерных для смет (Price, Cost, Quantity)
        $foundAttributes = 0;
        $attrKeywords = ['Price', 'Cost', 'Quantity', 'Measure', 'Unit', 'Name', 'Code'];
        
        // Берем случайный узел для проверки (первый попавшийся с атрибутами)
        $sampleNode = null;
        foreach ($xml->children() as $child) {
            if ($child->count() > 0 || $child->attributes()->count() > 0) {
                $sampleNode = $child;
                // Если есть внуки, берем их (скорее всего это позиции)
                if ($child->count() > 0) {
                    $sampleNode = $child->children()[0];
                }
                break;
            }
        }
        
        if ($sampleNode) {
            foreach ($attrKeywords as $keyword) {
                foreach ($sampleNode->attributes() as $k => $v) {
                    if (mb_stripos($k, $keyword) !== false) {
                        $foundAttributes++;
                        break;
                    }
                }
                // Также проверяем дочерние узлы как свойства
                foreach ($sampleNode->children() as $child) {
                     if (mb_stripos($child->getName(), $keyword) !== false) {
                        $foundAttributes++;
                        break;
                    }
                }
            }
        }
        
        if ($foundAttributes >= 2) {
            $indicators[] = 'has_estimate_attributes';
            $confidence += 15;
        }

        return [
            'confidence' => min($confidence, 100),
            'indicators' => $indicators,
        ];
    }
    
    public function getType(): string
    {
        return $this->detectedType;
    }
    
    public function getDescription(): string
    {
        return $this->description;
    }
}
