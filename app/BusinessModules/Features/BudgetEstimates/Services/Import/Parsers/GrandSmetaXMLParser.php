<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers;

use App\BusinessModules\Features\BudgetEstimates\Contracts\EstimateImportParserInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use Illuminate\Support\Facades\Log;

class GrandSmetaXMLParser implements EstimateImportParserInterface
{
    // Common XML namespaces for GrandSmeta/GGE
    private const NS_GGE = 'http://www.gge.ru/2001/Schema';
    
    private array $processedSysIds = [];

    public function parse(string $filePath): EstimateImportDTO
    {
        Log::error("[GrandSmeta] Parsing started: {$filePath}");
        $xml = $this->loadXML($filePath);
        
        $sections = [];
        $items = [];
        $this->processedSysIds = []; // Сброс списка обработанных ID
        
        // Поиск корневого узла для сметы (может быть разный в разных версиях)
        $estimateNode = $this->findEstimateNode($xml);
        
        // Парсим структуру
        $this->parseNodeRecursively($estimateNode, $sections, $items);
        
        // Получаем метаданные
        $metadata = $this->parseMetadata($xml);
        $metadata['estimate_type'] = 'grandsmeta';
        
        // Вычисляем итоговые суммы
        $totals = $this->calculateTotals($items);
        
        return new EstimateImportDTO(
            fileName: basename($filePath),
            fileSize: filesize($filePath),
            fileFormat: 'grandsmeta_xml',
            sections: $sections,
            items: $items,
            totals: $totals,
            metadata: $metadata,
            estimateType: 'grandsmeta',
            typeConfidence: 100.0
        );
    }

    public function detectStructure(string $filePath): array
    {
        $xml = $this->loadXML($filePath);
        $columns = $this->detectColumns($xml);
        
        return [
            'format' => 'grandsmeta_xml',
            'detected_columns' => $columns,
            'raw_headers' => [],
            'header_row' => null,
            'column_mapping' => [
                'section_number' => 'Number',
                'name' => 'Name',
                'unit' => 'Measure',
                'quantity' => 'Quant',
                'unit_price' => 'Price',
                'code' => 'Justification',
            ],
        ];
    }

    public function validateFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['xml', 'gsfx'])) {
            return false;
        }
        
        try {
            $xml = simplexml_load_file($filePath);
            if ($xml === false) {
                return false;
            }
            
            // Проверка корневых тегов, характерных для смет
            $rootName = $xml->getName();
            return in_array($rootName, ['GrandSmeta', 'Estimate', 'LocalEstimate', 'ObjectEstimate', 'GGE']);
            
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getSupportedExtensions(): array
    {
        return ['xml', 'gsfx'];
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
        $xml = simplexml_load_file($filePath, 'SimpleXMLElement', LIBXML_NOCDATA);
        
        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $messages = array_map(fn($e) => $e->message, $errors);
            throw new \Exception('Failed to parse XML: ' . implode(', ', $messages));
        }
        
        return $xml;
    }

    private function findEstimateNode(\SimpleXMLElement $xml): \SimpleXMLElement
    {
        // Иногда смета вложенная
        if (isset($xml->Estimate)) return $xml->Estimate;
        if (isset($xml->LocalEstimate)) return $xml->LocalEstimate;
        
        return $xml;
    }

    private function parseMetadata(\SimpleXMLElement $xml): array
    {
        $header = $xml->Header ?? $xml->Properties ?? null;
        
        return [
            'format' => 'grandsmeta_xml',
            'program_version' => (string)($xml['Generator'] ?? $xml['Version'] ?? ''),
            'estimate_name' => (string)($header->Name ?? $header->Caption ?? ''),
            'estimate_code' => (string)($header->Code ?? $header->Number ?? ''),
            'base_date' => (string)($header->BaseDate ?? ''),
            'current_date' => (string)($header->CurrentDate ?? date('Y-m-d')),
            'region' => (string)($header->Region ?? ''),
        ];
    }

    private function detectColumns(\SimpleXMLElement $xml): array
    {
        // XML структура фиксирована, возвращаем стандартный набор
        return [
            'number' => ['field' => 'section_number', 'header' => '№ п/п', 'confidence' => 1.0],
            'code' => ['field' => 'code', 'header' => 'Обоснование', 'confidence' => 1.0],
            'name' => ['field' => 'name', 'header' => 'Наименование', 'confidence' => 1.0],
            'unit' => ['field' => 'unit', 'header' => 'Ед. изм.', 'confidence' => 1.0],
            'quantity' => ['field' => 'quantity', 'header' => 'Количество', 'confidence' => 1.0],
            'unit_price' => ['field' => 'unit_price', 'header' => 'Цена за ед.', 'confidence' => 1.0],
        ];
    }

    private function parseNodeRecursively(\SimpleXMLElement $node, array &$sections, array &$items, string $parentPath = '', int $level = 0): void
    {
        // Игнорируем узлы Itog, которые могут попасться при рекурсивном обходе
        if (in_array(strtolower($node->getName()), ['itog', 'itogres'])) {
            return;
        }

        // Обработка Разделов (Section, Razdel, Chapter)
        $sectionNodes = [];
        if (isset($node->Sections->Section)) $sectionNodes = $node->Sections->Section;
        elseif (isset($node->Razdel)) $sectionNodes = $node->Razdel;
        elseif (isset($node->Chapter)) $sectionNodes = $node->Chapter;
        
        foreach ($sectionNodes as $section) {
            $this->processSection($section, $sections, $items, $parentPath, $level);
        }

        // Обработка Позиций (Positions, Items, Item)
        $itemNodes = [];
        if (isset($node->Positions->Position)) $itemNodes = $node->Positions->Position;
        elseif (isset($node->Items->Item)) $itemNodes = $node->Items->Item;
        elseif (isset($node->Poz)) $itemNodes = $node->Poz; // GGE style sometimes
        
        foreach ($itemNodes as $item) {
            $this->processItem($item, $items, $parentPath, $level);
        }
        
        // Если это плоский список и нет явных коллекций, перебираем детей
        if (empty($sectionNodes) && empty($itemNodes)) {
             foreach ($node->children() as $child) {
                 $name = strtolower($child->getName());
                 
                 // Явно игнорируем Itog и прочие служебные теги
                 if (in_array($name, ['itog', 'itogres', 'reportoptions', 'properties', 'header', 'signature'])) {
                     continue;
                 }

                 if (in_array($name, ['section', 'razdel', 'chapter'])) {
                     $this->processSection($child, $sections, $items, $parentPath, $level);
                 } elseif (in_array($name, ['position', 'item', 'poz'])) {
                     $this->processItem($child, $items, $parentPath, $level);
                 }
             }
        }
    }

    private function processSection(\SimpleXMLElement $section, array &$sections, array &$items, string $parentPath, int $level): void
    {
        // Извлечение атрибутов раздела
        $num = (string)($section['Number'] ?? $section['Num'] ?? $section->Number ?? '');
        $name = (string)($section['Name'] ?? $section['Caption'] ?? $section->Name ?? $section->Caption ?? '');
        
        $currentPath = $parentPath ? "$parentPath.$num" : $num;
        
        $sections[] = (new EstimateImportRowDTO(
            rowNumber: 0, 
            sectionNumber: $num,
            itemName: $name ?: "Раздел $num",
            unit: null, 
            quantity: null, 
            unitPrice: null, 
            code: null,
            isSection: true,
            level: $level + 1,
            sectionPath: $currentPath,
            isNotAccounted: false
        ))->toArray();

        // Рекурсия
        $this->parseNodeRecursively($section, $sections, $items, $currentPath, $level + 1);
    }

    private function processItem(\SimpleXMLElement $item, array &$items, string $parentPath, int $level): void
    {
        // Проверка на дубликаты по SysID
        $sysId = (string)($item['SysID'] ?? $item->attributes()->SysID ?? '');
        $code = (string)($item['Justification'] ?? $item['Code'] ?? $item->Code ?? $item->Justification ?? '');
        
        // Log::error("[GrandSmeta] Processing Item. SysID: '{$sysId}', Code: '{$code}'");

        if (!empty($sysId)) {
            if (in_array($sysId, $this->processedSysIds)) {
                // Log::info("[GrandSmeta] Skipping duplicate SysID: {$sysId}");
                return; // Пропускаем дубликат
            }
            $this->processedSysIds[] = $sysId;
        }

        // Извлечение основных полей
        $num = (string)($item['Number'] ?? $item['Num'] ?? $item->Number ?? '');
        $code = (string)($item['Justification'] ?? $item['Code'] ?? $item->Code ?? $item->Justification ?? '');
        $name = (string)($item['Name'] ?? $item['Caption'] ?? $item->Name ?? $item->Caption ?? '');
        $unit = (string)($item['Measure'] ?? $item['Unit'] ?? $item->Measure ?? $item->Unit ?? '');
        
        // Количество
        $qty = (float)($item['Quant'] ?? $item['Quantity'] ?? $item->Quant ?? $item->Quantity ?? 0);
        
        // Дополнительная проверка на служебные позиции (DBFlags="TechK")
        // Проверяем атрибут DBFlags различными способами доступа к SimpleXML
        $dbFlags = (string)($item['DBFlags'] ?? $item->attributes()->DBFlags ?? '');
        
        if (str_contains($dbFlags, 'TechK')) {
            // Log::info("Skipping TechK position: {$code} ({$name})");
            return; // Пропускаем технологические карты/ресурсы, чтобы не дублировать суммы
        }

        // Цена за единицу (прямые затраты)
        $price = 0.0;
        if (isset($item->Price)) {
            $price = (float)($item->Price['Value'] ?? $item->Price);
        } elseif (isset($item->UnitCost)) {
            $price = (float)($item->UnitCost['Value'] ?? $item->UnitCost);
        } elseif (isset($item->Direct)) {
            $price = (float)$item->Direct;
        }

        // Общая стоимость
        $total = 0.0;
        if (isset($item->Cost)) {
            $total = (float)($item->Cost['Value'] ?? $item->Cost);
        } elseif (isset($item->TotalCost)) {
            $total = (float)$item->TotalCost;
        } else {
            $total = $qty * $price;
        }
        
        // Проверка на неучтенные ресурсы (часто в тегах <Resource> внутри <Resources>)
        // Но здесь мы парсим саму позицию. Если это ресурс внутри позиции - он может быть отдельной строкой.
        // Для простоты, если позиция имеет тип "Material" или "Resource", ставим флаг.
        // ГрандСмета обычно помечает неучтенные материалы отдельно.
        
        $isNotAccounted = false;
        // Эвристика: если в обосновании есть "ЦЕНА" или текст в скобках (прим), возможно это неучтенка
        if (mb_stripos($code, 'цена') !== false) {
             $isNotAccounted = true;
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
            isNotAccounted: $isNotAccounted,
            rawData: (array)$item
        ))->toArray();
        
        // Обработка вложенных ресурсов (часто ГрандСмета показывает их как подпункты)
        if (isset($item->Resources->Resource)) {
            foreach ($item->Resources->Resource as $res) {
                $this->processResource($res, $items, $parentPath, $level);
            }
        }
    }

    private function processResource(\SimpleXMLElement $res, array &$items, string $parentPath, int $level): void
    {
        $code = (string)($res['Code'] ?? $res->Code ?? '');
        $name = (string)($res['Name'] ?? $res->Name ?? '');
        $unit = (string)($res['Measure'] ?? $res->Measure ?? '');
        $qty = (float)($res['Quant'] ?? $res->Quantity ?? 0);
        $price = (float)($res['Price'] ?? $res->Price ?? 0);
        
        $items[] = (new EstimateImportRowDTO(
            rowNumber: 0,
            sectionNumber: null,
            itemName: $name,
            unit: $unit,
            quantity: $qty,
            unitPrice: $price,
            code: $code,
            isSection: false,
            level: $level + 1, // Вложенный уровень
            sectionPath: $parentPath,
            isNotAccounted: true, // Ресурсы под позицией часто являются материалами
            itemType: 'material'
        ))->toArray();
    }
    
    private function calculateTotals(array $items): array
    {
        $totalAmount = 0;
        $totalQuantity = 0;
        
        foreach ($items as $item) {
            $q = $item['quantity'] ?? 0;
            $p = $item['unit_price'] ?? 0;
            // Если есть уже посчитанная сумма, берем её, иначе считаем
            $amount = $item['current_total_amount'] ?? ($q * $p);
            
            $totalAmount += $amount;
            $totalQuantity += $q;
        }
        
        return [
            'total_amount' => $totalAmount,
            'total_quantity' => $totalQuantity,
            'items_count' => count($items),
        ];
    }
}
