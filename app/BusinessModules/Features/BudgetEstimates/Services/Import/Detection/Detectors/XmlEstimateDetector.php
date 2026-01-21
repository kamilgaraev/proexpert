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
    public function detect($content): array
    {
        $indicators = [];
        $confidence = 0;
        
        $xml = null;
        
        // 1. Пытаемся получить XML объект
        if ($content instanceof SimpleXMLElement) {
            $xml = $content;
        } elseif (is_string($content)) {
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
                break;
            }
        }

        // Проверка атрибута Generator
        $generator = (string)($xml['Generator'] ?? '');
        if (!empty($generator)) {
            $indicators[] = "generator_{$generator}";
            // Если генератор известен (GrandSmeta, Smeta.ru и т.д.), повышаем уверенность
            if (mb_stripos($generator, 'GrandSmeta') !== false || 
                mb_stripos($generator, 'Smeta') !== false || 
                mb_stripos($generator, 'GGE') !== false) {
                $confidence += 30;
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
        return 'xml_estimate';
    }
    
    public function getDescription(): string
    {
        return 'XML Смета (ГрандСмета, GGE или совместимый формат)';
    }
}
