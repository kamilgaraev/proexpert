<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers;

use App\BusinessModules\Features\BudgetEstimates\Contracts\EstimateImportParserInterface;
use App\BusinessModules\Features\BudgetEstimates\Contracts\StreamParserInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use Illuminate\Support\Facades\Log;
use Generator;

class UniversalXmlParser implements EstimateImportParserInterface, StreamParserInterface
{
    private const NS_GGE = 'http://www.gge.ru/2001/Schema';

    public function parse(string $filePath): EstimateImportDTO|Generator
    {
        // If called by ParserFactory (StreamParserInterface), return Generator
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $backtrace[1]['class'] ?? '';
        
        if (str_contains($caller, 'ParserFactory') || str_contains($caller, 'EstimateImportService')) {
             return $this->streamParse($filePath);
        }
        
        // Otherwise return DTO (EstimateImportParserInterface)
        return $this->fullParse($filePath);
    }

    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), $this->getSupportedExtensions());
    }

    private function streamParse(string $filePath): Generator
    {
         $dto = $this->fullParse($filePath);
         foreach ($dto->items as $item) {
             yield $item;
         }
    }

    private function fullParse(string $filePath): EstimateImportDTO
    {
        $xml = $this->loadXML($filePath);
        
        $sections = [];
        $items = [];
        
        // 1. Попытка найти стандартную структуру (ГрандСмета, GGE)
        $estimateNode = $this->findEstimateNode($xml);
        
        if ($estimateNode) {
            Log::info('[XmlParser] Found structured estimate node', ['node' => $estimateNode->getName()]);
            $this->parseNodeRecursively($estimateNode, $sections, $items);
        } else {
            // 2. Эвристический поиск позиций, если структура не распознана
            Log::warning('[XmlParser] Structured estimate node not found, using heuristics');
            $this->findItemsHeuristically($xml, $items);
        }
        
        // Метаданные
        $metadata = $this->parseMetadata($xml);
        $metadata['parser'] = 'universal_xml';
        
        // Итоги
        $totals = $this->calculateTotals($items);
        
        return new EstimateImportDTO(
            fileName: basename($filePath),
            fileSize: filesize($filePath),
            fileFormat: 'xml',
            sections: $sections,
            items: $items,
            totals: $totals,
            metadata: $metadata,
            estimateType: 'xml_auto',
            typeConfidence: 100.0
        );
    }

    public function detectStructure(string $filePath): array
    {
        // Для XML структура обычно фиксирована или самоописываема
        return [
            'format' => 'xml',
            'detected_columns' => $this->detectColumns(),
            'raw_headers' => [],
            'header_row' => null,
            'column_mapping' => [], // XML usually doesn't need column mapping
        ];
    }

    public function validateFile(string $filePath): bool
    {
        if (!file_exists($filePath)) return false;
        
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($ext, $this->getSupportedExtensions())) return false;
        
        try {
            // Быстрая проверка на валидность XML
            $content = file_get_contents($filePath, false, null, 0, 1024); // Читаем начало
            return str_contains($content, '<?xml') || str_contains($content, '<GrandSmeta') || str_contains($content, '<Estimate');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getSupportedExtensions(): array
    {
        return ['xml', 'gsfx', 'gge'];
    }

    public function getHeaderCandidates(): array
    {
        return [];
    }

    public function readContent(string $filePath, int $maxRows = 100)
    {
        return $this->loadXML($filePath);
    }

    public function detectStructureFromRow(string $filePath, int $headerRow): array
    {
        return $this->detectStructure($filePath);
    }

    private function loadXML(string $filePath): \SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        
        $content = file_get_contents($filePath);
        
        // Попытка исправить кодировку, если она кривая (часто бывает Windows-1251 без объявления)
        if (!preg_match('/encoding=["\'](.*?)["\']/', $content)) {
            // Если нет объявления кодировки, но есть кириллица в 1251
            if ($this->isWindows1251($content)) {
                $content = '<?xml version="1.0" encoding="windows-1251"?>' . "\n" . $content;
            }
        }
        
        try {
            $xml = new \SimpleXMLElement($content);
        } catch (\Exception $e) {
            // Fallback: попробуем sanitize
            $content = $this->sanitizeXml($content);
            try {
                $xml = new \SimpleXMLElement($content);
            } catch (\Exception $e2) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                throw new \RuntimeException('XML Parsing failed: ' . $e2->getMessage());
            }
        }
        
        return $xml;
    }
    
    private function isWindows1251($string): bool
    {
        // Простая эвристика: если не UTF-8 валидный, то вероятно 1251
        return !mb_check_encoding($string, 'UTF-8');
    }
    
    private function sanitizeXml($content): string
    {
        // Удаляем невалидные символы
        return preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $content);
    }

    private function findEstimateNode(\SimpleXMLElement $xml): ?\SimpleXMLElement
    {
        // Ищем корневой узел сметы
        $candidates = ['Estimate', 'LocalEstimate', 'ObjectEstimate', 'LSR', 'Smeta', 'Document'];
        
        foreach ($candidates as $name) {
            if (isset($xml->$name)) return $xml->$name;
            // Case insensitive search
            foreach ($xml->children() as $child) {
                if (strcasecmp($child->getName(), $name) === 0) return $child;
            }
        }
        
        // Если сам корень похож на смету
        if (in_array($xml->getName(), $candidates)) return $xml;
        
        return null;
    }

    private function parseMetadata(\SimpleXMLElement $xml): array
    {
        $header = $xml->Header ?? $xml->Properties ?? $xml->Info ?? null;
        
        return [
            'program' => (string)($xml['Generator'] ?? $xml['Program'] ?? ''),
            'version' => (string)($xml['Version'] ?? ''),
            'date' => (string)($header->Date ?? $header->BaseDate ?? date('Y-m-d')),
            'name' => (string)($header->Name ?? $header->Caption ?? $header->Title ?? ''),
        ];
    }

    private function detectColumns(): array
    {
        return [
            'number' => ['field' => 'section_number', 'header' => '№', 'confidence' => 1.0],
            'code' => ['field' => 'code', 'header' => 'Шифр', 'confidence' => 1.0],
            'name' => ['field' => 'name', 'header' => 'Наименование', 'confidence' => 1.0],
            'unit' => ['field' => 'unit', 'header' => 'Ед. изм.', 'confidence' => 1.0],
            'quantity' => ['field' => 'quantity', 'header' => 'Кол-во', 'confidence' => 1.0],
            'unit_price' => ['field' => 'unit_price', 'header' => 'Цена', 'confidence' => 1.0],
            'total_cost' => ['field' => 'total_cost', 'header' => 'Всего', 'confidence' => 1.0],
        ];
    }

    private function parseNodeRecursively(\SimpleXMLElement $node, array &$sections, array &$items, string $parentPath = '', int $level = 0): void
    {
        // 1. Ищем разделы
        $sectionKeywords = ['Section', 'Razdel', 'Chapter', 'Part', 'Stage'];
        $foundSections = [];
        
        foreach ($node->children() as $child) {
            if ($this->matchesKeywords($child->getName(), $sectionKeywords)) {
                $foundSections[] = $child;
            }
        }
        
        foreach ($foundSections as $section) {
            $this->processSection($section, $sections, $items, $parentPath, $level);
        }

        // 2. Ищем позиции
        $itemKeywords = ['Position', 'Item', 'Poz', 'Line', 'Row', 'Work'];
        $foundItems = [];
        
        foreach ($node->children() as $child) {
            if ($this->matchesKeywords($child->getName(), $itemKeywords)) {
                $foundItems[] = $child;
            }
        }
        
        foreach ($foundItems as $item) {
            $this->processItem($item, $items, $parentPath, $level);
        }
        
        // Если ничего не нашли, но есть дети, пробуем рекурсивно искать в них (если это не позиция)
        if (empty($foundSections) && empty($foundItems)) {
            foreach ($node->children() as $child) {
                // Избегаем глубокого спуска в поля (Price, Resources и т.д.)
                if (!$this->isLeafNode($child)) {
                    $this->parseNodeRecursively($child, $sections, $items, $parentPath, $level);
                }
            }
        }
    }
    
    private function isLeafNode(\SimpleXMLElement $node): bool
    {
        // Считаем узел "листом" (свойствами), если у него нет детей или его имя похоже на свойство
        $propKeywords = ['Price', 'Cost', 'Quantity', 'Measure', 'Unit', 'Name', 'Code', 'Resources', 'Coefficients'];
        if ($this->matchesKeywords($node->getName(), $propKeywords)) return true;
        
        return $node->count() === 0;
    }

    private function processSection(\SimpleXMLElement $section, array &$sections, array &$items, string $parentPath, int $level): void
    {
        $num = $this->extractValue($section, ['Number', 'Num', 'No', 'Id']);
        $name = $this->extractValue($section, ['Name', 'Caption', 'Title']);
        
        $currentPath = $parentPath ? "$parentPath.$num" : $num;
        
        $sections[] = (new EstimateImportRowDTO(
            rowNumber: 0,
            sectionNumber: $num,
            itemName: $name ?: "Раздел $num",
            unit: null, quantity: null, unitPrice: null, code: null,
            isSection: true,
            level: $level + 1,
            sectionPath: $currentPath,
            isNotAccounted: false
        ))->toArray();

        $this->parseNodeRecursively($section, $sections, $items, $currentPath, $level + 1);
    }

    private function processItem(\SimpleXMLElement $item, array &$items, string $parentPath, int $level): void
    {
        $num = $this->extractValue($item, ['Number', 'Num', 'No']);
        $code = $this->extractValue($item, ['Justification', 'Code', 'Cipher', 'Shifr']);
        $name = $this->extractValue($item, ['Name', 'Caption', 'Title', 'Description']);
        $unit = $this->extractValue($item, ['Measure', 'Unit', 'EdIzm']);
        
        $qty = (float)$this->extractValue($item, ['Quant', 'Quantity', 'Volume', 'Kol']);
        
        // Цена
        $price = 0.0;
        $priceNode = $item->Price ?? $item->UnitCost ?? $item->Direct ?? null;
        if ($priceNode) {
            $price = (float)($priceNode['Value'] ?? $priceNode);
        } else {
            // Поиск по атрибутам или детям
            $price = (float)$this->extractValue($item, ['Price', 'UnitCost', 'Cena']);
        }
        
        // Стоимость
        $total = 0.0;
        $costNode = $item->Cost ?? $item->TotalCost ?? $item->Total ?? null;
        if ($costNode) {
            $total = (float)($costNode['Value'] ?? $costNode);
        } else {
            $total = (float)$this->extractValue($item, ['Cost', 'TotalCost', 'Summa']);
        }
        
        if ($total == 0 && $qty > 0 && $price > 0) {
            $total = $qty * $price;
        }

        $items[] = (new EstimateImportRowDTO(
            rowNumber: 0,
            sectionNumber: $num,
            itemName: $name,
            unit: $unit,
            quantity: $qty,
            unitPrice: $price,
            code: $code,
            isSection: false,
            level: $level,
            sectionPath: $parentPath,
            currentTotalAmount: $total,
            isNotAccounted: false,
            rawData: (array)$item
        ))->toArray();
        
        // Ресурсы
        if (isset($item->Resources->Resource)) {
             foreach ($item->Resources->Resource as $res) {
                 $this->processResource($res, $items, $parentPath, $level);
             }
        }
    }
    
    private function processResource(\SimpleXMLElement $res, array &$items, string $parentPath, int $level): void
    {
        $name = $this->extractValue($res, ['Name', 'Caption']);
        $code = $this->extractValue($res, ['Code', 'Justification']);
        $unit = $this->extractValue($res, ['Measure', 'Unit']);
        $qty = (float)$this->extractValue($res, ['Quant', 'Quantity']);
        $price = (float)$this->extractValue($res, ['Price', 'Cost']);
        
        $items[] = (new EstimateImportRowDTO(
            rowNumber: 0,
            sectionNumber: null,
            itemName: $name,
            unit: $unit,
            quantity: $qty,
            unitPrice: $price,
            code: $code,
            isSection: false,
            level: $level + 1,
            sectionPath: $parentPath,
            isNotAccounted: true,
            itemType: 'material'
        ))->toArray();
    }

    private function findItemsHeuristically(\SimpleXMLElement $xml, array &$items): void
    {
        // Ищем повторяющиеся узлы, у которых есть атрибуты или дети, похожие на цену/количество/название
        $allNodes = [];
        $this->collectAllNodes($xml, $allNodes);
        
        // Группируем по имени тега
        $groups = [];
        foreach ($allNodes as $node) {
            $name = $node->getName();
            $groups[$name][] = $node;
        }
        
        // Ищем группу, где элементы имеют нужные поля
        $bestGroup = [];
        $maxScore = 0;
        
        foreach ($groups as $name => $nodes) {
            if (count($nodes) < 2) continue; // Игнорируем одиночные узлы
            
            $score = 0;
            $sample = $nodes[0];
            
            if ($this->hasField($sample, ['Name', 'Caption', 'Title', 'Description'])) $score += 2;
            if ($this->hasField($sample, ['Price', 'Cost', 'Sum', 'Value'])) $score += 3;
            if ($this->hasField($sample, ['Quant', 'Quantity', 'Vol', 'Count'])) $score += 3;
            if ($this->hasField($sample, ['Unit', 'Measure'])) $score += 1;
            
            if ($score > $maxScore) {
                $maxScore = $score;
                $bestGroup = $nodes;
            }
        }
        
        if ($maxScore >= 5) { // Достаточно уверенности
            Log::info('[XmlParser] Heuristic found items', ['count' => count($bestGroup), 'tag' => $bestGroup[0]->getName()]);
            foreach ($bestGroup as $node) {
                $this->processItem($node, $items, '', 0);
            }
        }
    }
    
    private function collectAllNodes(\SimpleXMLElement $node, array &$collection): void
    {
        foreach ($node->children() as $child) {
            $collection[] = $child;
            $this->collectAllNodes($child, $collection);
        }
    }

    private function matchesKeywords(string $name, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (mb_stripos($name, $keyword) !== false) return true;
        }
        return false;
    }
    
    private function extractValue(\SimpleXMLElement $node, array $keys): string
    {
        foreach ($keys as $key) {
            // Check attributes
            if (isset($node[$key])) return (string)$node[$key];
            // Check children
            if (isset($node->$key)) return (string)$node->$key;
            
            // Case insensitive check for attributes
            foreach ($node->attributes() as $k => $v) {
                if (strcasecmp($k, $key) === 0) return (string)$v;
            }
        }
        return '';
    }
    
    private function hasField(\SimpleXMLElement $node, array $keys): bool
    {
        return $this->extractValue($node, $keys) !== '';
    }

    private function calculateTotals(array $items): array
    {
        $totalAmount = 0;
        $totalQuantity = 0;
        
        foreach ($items as $item) {
            $totalAmount += ($item['current_total_amount'] ?? 0);
            $totalQuantity += ($item['quantity'] ?? 0);
        }
        
        return [
            'total_amount' => $totalAmount,
            'total_quantity' => $totalQuantity,
            'items_count' => count($items),
        ];
    }
}
