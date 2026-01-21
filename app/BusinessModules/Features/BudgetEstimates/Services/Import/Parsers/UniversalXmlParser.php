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
        // Determine caller to decide between Stream (Generator) and Full (DTO) parsing
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $callerClass = $backtrace[1]['class'] ?? '';
        $callerFunction = $backtrace[1]['function'] ?? '';
        
        // If called by ParserFactory or specifically for streaming execution
        if (str_contains($callerClass, 'ParserFactory') || 
           (str_contains($callerClass, 'EstimateImportService') && str_contains($callerFunction, 'createEstimateFromStream'))) {
             return $this->streamParse($filePath);
        }
        
        // Default to full DTO (for preview and other usages)
        return $this->fullParse($filePath);
    }

    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), $this->getSupportedExtensions());
    }

    private function streamParse(string $filePath): Generator
    {
         $dto = $this->fullParse($filePath);
         
         // Сначала отдаем разделы, чтобы создать структуру
         foreach ($dto->sections as $section) {
             yield $section;
         }
         
         // Затем позиции
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
        if ($xml) {
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
        } else {
            // FALLBACK: Если XML не загрузился, пробуем Regex-парсинг (для битых файлов)
            Log::warning('[XmlParser] XML load failed, attempting regex parsing');
            $this->parseWithRegex($filePath, $sections, $items);
            $metadata = ['program' => 'GrandSmeta (Regex)', 'date' => date('Y-m-d')];
        }
        
        $metadata['parser'] = 'universal_xml';
        
        // Определяем тип сметы по генератору
        $estimateType = 'xml_auto';
        $generator = $metadata['program'] ?? '';
        
        if (mb_stripos($generator, 'GrandSmeta') !== false) {
            $estimateType = 'grandsmeta';
        } elseif (mb_stripos($generator, 'Smeta.ru') !== false) {
            $estimateType = 'smartsmeta';
        } elseif (mb_stripos($generator, 'RIK') !== false) {
            $estimateType = 'rik';
        }
        
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
            estimateType: $estimateType,
            typeConfidence: 100.0
        );
    }
    
    private function prepareContent(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        if (!$content) return null;

        // 1. Очистка BOM и trim
        $content = trim($content);
        $bom = pack('H*','EFBBBF');
        $content = preg_replace("/^$bom/", '', $content);
        
        // 2. Детекция и исправление кодировки
        // Сначала ищем объявление encoding в заголовке
        $hasEncoding = str_contains($content, 'encoding=');
        
        if (!$hasEncoding) {
            // Если заголовка нет, проверяем на UTF-8
            if (!mb_check_encoding($content, 'UTF-8')) {
                // Если не UTF-8, пробуем конвертировать из Windows-1251 (самый частый кейс для РФ)
                try {
                    $converted = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
                    if ($converted) {
                        $content = $converted;
                        // Добавляем/исправляем заголовок
                        if (!str_contains($content, '<?xml')) {
                            $content = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . $content;
                        } else {
                            $content = str_replace('<?xml version="1.0"?>', '<?xml version="1.0" encoding="utf-8"?>', $content);
                        }
                    }
                } catch (\Throwable $e) {
                    // Ignore conversion errors
                }
            }
        } elseif (preg_match('/encoding=["\']windows-1251["\']/i', $content)) {
             // Если явно указано 1251, но мы хотим работать с UTF-8 строкой для Regex
             // (SimpleXML сам справится с 1251, если она указана, но для Regex нам нужен UTF-8)
             // НО! Если мы поменяем кодировку строки, нам нужно поменять и заголовок на utf-8, 
             // иначе simplexml сойдет с ума (строка utf-8, а заголовок 1251).
             
             // Лучше оставим как есть для simplexml (он умеет читать encoding),
             // но для parseWithRegex нам придется конвертировать вручную.
        }

        // 3. Санитизация (только валидные UTF-8 символы)
        // Делаем это только если строка валидный UTF-8, иначе испортим 1251
        if (mb_check_encoding($content, 'UTF-8')) {
            $content = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $content);
        }
        
        return $content;
    }

    private function loadXML(string $filePath): ?\SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        
        // Используем подготовленный контент
        $content = $this->prepareContent($filePath);
        
        if (!$content) return null;
        
        try {
            return new \SimpleXMLElement($content);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function parseWithRegex(string $filePath, array &$sections, array &$items): void
    {
        // Для Regex нам ОБЯЗАТЕЛЬНО нужен UTF-8, чтобы корректно искать кириллицу
        $content = file_get_contents($filePath);
        
        // Принудительная конвертация в UTF-8 для Regex парсинга
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }
        
        // Санитизация
        $content = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $content);

        // 1. Поиск позиций
        // Улучшенный паттерн с поддержкой многострочности и unicode (u modifier)
        preg_match_all('/<(Item|Position|Line|Row)([^>]+)>/iu', $content, $matches);
        
        if (!empty($matches[2])) {
            foreach ($matches[2] as $attrs) {
                $item = [];
                // Парсим атрибуты с поддержкой кириллицы
                preg_match('/(Caption|Name|Title|Naim|Description|Наименование)="([^"]+)"/iu', $attrs, $mName);
                preg_match('/(Quant|Quantity|Volume|Fit|Vol|Kol|Количество)="([^"]+)"/iu', $attrs, $mQty);
                preg_match('/(Price|Cost|UnitCost|PriceCurr|Цена)="([^"]+)"/iu', $attrs, $mPrice);
                preg_match('/(Unit|Measure|Units|EdIzm|ЕдИзм)="([^"]+)"/iu', $attrs, $mUnit);
                
                // Важно: имя должно быть
                $name = $mName[2] ?? null;
                if (!$name) continue;
                
                $items[] = (new EstimateImportRowDTO(
                    rowNumber: 0,
                    sectionNumber: null,
                    itemName: html_entity_decode($name), // Декодируем entities если есть
                    unit: isset($mUnit[2]) ? html_entity_decode($mUnit[2]) : null,
                    quantity: (float)str_replace(',', '.', ($mQty[2] ?? 0)), // Replace comma for float
                    unitPrice: (float)str_replace(',', '.', ($mPrice[2] ?? 0)),
                    code: null,
                    isSection: false,
                    level: 1,
                    sectionPath: null,
                    isNotAccounted: false,
                    rawData: ['attributes' => $attrs]
                ))->toArray();
            }
        }
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
        
        foreach ($foundSections as $i => $section) {
            $this->processSection($section, $sections, $items, $parentPath, $level, $i + 1);
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
        $propKeywords = ['Price', 'Cost', 'Quantity', 'Measure', 'Unit', 'Name', 'Code', 'Resources', 'Coefficients', 'Itog'];
        if ($this->matchesKeywords($node->getName(), $propKeywords)) return true;
        
        return $node->count() === 0;
    }

    private function processSection(\SimpleXMLElement $section, array &$sections, array &$items, string $parentPath, int $level, int $index = 1): void
    {
        $num = $this->extractValue($section, ['Number', 'Num', 'No', 'Id']);
        if (!$num) {
            $num = (string)$index;
        }
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

    private function xmlToArray($xml): array
    {
        if ($xml instanceof \SimpleXMLElement) {
            $attributes = [];
            foreach ($xml->attributes() as $k => $v) {
                $attributes[$k] = (string)$v;
            }
            
            $children = [];
            foreach ($xml->children() as $k => $v) {
                $children[$k][] = $this->xmlToArray($v);
            }
            
            // Simplify children if only one
            foreach ($children as $k => $v) {
                if (count($v) === 1) {
                    $children[$k] = $v[0];
                }
            }
            
            $value = (string)$xml;
            
            $result = [];
            if (!empty($attributes)) $result['@attributes'] = $attributes;
            if (!empty($children)) $result = array_merge($result, $children);
            if (trim($value) !== '' && empty($children)) $result['@value'] = $value;
            
            return $result;
        }
        
        return (array)$xml;
    }

    private function processItem(\SimpleXMLElement $item, array &$items, string $parentPath, int $level): void
    {
        $num = $this->extractValue($item, ['Number', 'Num', 'No']);
        $code = $this->extractValue($item, ['Justification', 'Code', 'Cipher', 'Shifr', 'Identifier']);
        $name = $this->extractValue($item, ['Name', 'Caption', 'Title', 'Description']);
        $unit = $this->extractValue($item, ['Measure', 'Unit', 'Units', 'EdIzm']);
        
        // 1. Robust Quantity Detection
        $qty = (float)$this->extractValue($item, ['Fit', 'FizObjem', 'Volume', 'Vol', 'Quant', 'Quantity', 'Kol']);
        
        // Coefficient check from Unit
        $quantityCoefficient = 1.0;
        if ($unit && preg_match('/^(\d+)\s+/', trim($unit), $matches)) {
            $val = (float)$matches[1];
            if ($val > 0) $quantityCoefficient = $val;
        }

        // Цена и Стоимость
        $price = 0.0;
        $total = 0.0;

        // 2. Пытаемся найти прямые атрибуты
        $priceNode = $item->Price ?? $item->UnitCost ?? $item->Direct ?? null;
        if ($priceNode) {
            $price = (float)($priceNode['Value'] ?? $priceNode);
        } else {
            $price = (float)$this->extractValue($item, ['Price', 'UnitCost', 'Cena']);
        }

        $costNode = $item->Cost ?? $item->TotalCost ?? $item->Total ?? null;
        if ($costNode) {
            $total = (float)($costNode['Value'] ?? $costNode);
        } else {
            $total = (float)$this->extractValue($item, ['Cost', 'TotalCost', 'Summa']);
        }

        // 3. Специфика ГрандСметы: Ищем стоимость в итогах (<Itog>)
        if (isset($item->Itog)) {
            $bestTotal = 0.0;
            $foundBest = false;
            
            // Check specific attributes
            $targetAttrs = ['TotalWithNRSP', 'TotalWithHP_SP', 'TotalWithHPSP', 'Total'];
            foreach ($targetAttrs as $attr) {
                if (isset($item->Itog[$attr])) {
                    $val = (float)$item->Itog[$attr];
                    if ($val > 0) {
                        $bestTotal = $val;
                        $foundBest = true;
                        break;
                    }
                }
            }
            
            if (!$foundBest) {
                // Ищем во вложенных итогах
                $itogs = $item->xpath('.//Itog[@TotalCurr] | .//Itog[@Total]');
                foreach ($itogs as $itogXml) {
                    $val = (float)($itogXml['TotalCurr'] ?? $itogXml['Total'] ?? 0);
                    $caption = (string)($itogXml['Caption'] ?? '');
                    
                    if (mb_stripos($caption, 'Всего') !== false || $val > $bestTotal) {
                        $bestTotal = $val;
                    }
                }
            }
            
            if ($bestTotal > 0) {
                $total = $bestTotal;
            }
        }
        
        // 4. Учет НР и СП
        $hp = (float)$this->extractValue($item, ['HP', 'Overhead']);
        $sp = (float)$this->extractValue($item, ['SP', 'Profit']);
        
        // 5. Resource Summation Fallback
        $resourcesTotal = 0.0;
        $hasResources = isset($item->Resources) && count($item->Resources->children()) > 0;
        
        if ($hasResources) {
            foreach ($item->Resources->children() as $res) {
                $nodeName = $res->getName();
                if (in_array($nodeName, ['Tzr', 'Tzm'])) continue; // Skip labor if needed, but keeping for cost sum

                $resTotal = (float)$this->extractValue($res, ['Total', 'Cost', 'Summa']);
                if ($resTotal == 0) {
                    $resQty = (float)$this->extractValue($res, ['Quant', 'Quantity']);
                    $resPrice = (float)$this->extractValue($res, ['Price', 'PriceCurr']);
                    $resTotal = $resQty * $resPrice;
                }
                $resourcesTotal += $resTotal;
            }
        }

        // Если цена 0, и есть ресурсы - берем сумму ресурсов
        if ($total == 0 && $resourcesTotal > 0) {
            $total = $resourcesTotal;
        }

        // 6. Back calculation
        if ($price == 0 && $qty > 0 && $total > 0) {
            $price = $total / $qty;
        } elseif ($total == 0 && $qty > 0 && $price > 0) {
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
            rawData: $this->xmlToArray($item),
            quantityCoefficient: $quantityCoefficient
        ))->toArray();
        
        // Ресурсы
        if (isset($item->Resources)) {
             foreach ($item->Resources->children() as $res) {
                 $this->processResource($res, $items, $parentPath, $level);
             }
        }
    }
    
    private function processResource(\SimpleXMLElement $res, array &$items, string $parentPath, int $level): void
    {
        $nodeName = $res->getName();
        if (in_array($nodeName, ['Tzr', 'Tzm'])) {
            return;
        }

        $name = $this->extractValue($res, ['Name', 'Caption']);
        $code = $this->extractValue($res, ['Code', 'Justification']);
        $unit = $this->extractValue($res, ['Measure', 'Unit', 'Units']);
        $qty = (float)$this->extractValue($res, ['Quant', 'Quantity', 'Fit', 'FizObjem']);
        
        $price = 0.0;
        if (isset($res->PriceCurr)) {
            $price = (float)($res->PriceCurr['Value'] ?? $res->PriceCurr);
        } elseif (isset($res->PriceBase)) {
            $price = (float)($res->PriceBase['Value'] ?? $res->PriceBase);
        } else {
            $price = (float)$this->extractValue($res, ['Price', 'Cost']);
        }
        
        if ($price == 0) {
             $total = (float)$this->extractValue($res, ['Total', 'Cost']);
             if ($total > 0 && $qty > 0) {
                 $price = $total / $qty;
             }
        }

        if (empty($name) && empty($code)) return;

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
            itemType: 'material',
            rawData: $this->xmlToArray($res)
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
