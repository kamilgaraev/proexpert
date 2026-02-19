<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers;

use App\BusinessModules\Features\BudgetEstimates\Contracts\EstimateImportParserInterface;
use App\BusinessModules\Features\BudgetEstimates\Contracts\StreamParserInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use Illuminate\Support\Facades\Log;
use Generator;

class GrandSmetaXMLParser implements EstimateImportParserInterface, StreamParserInterface
{
    // Common XML namespaces for GrandSmeta/GGE
    private const NS_GGE = 'http://www.gge.ru/2001/Schema';
    
    private const IGNORED_SECTIONS = [
        'сводка затрат',
        'ведомость ресурсов',
        'ресурсы',
    ];

    private array $processedSysIds = [];

    public function parse(string $filePath): EstimateImportDTO|Generator
    {
        Log::info("[GrandSmeta] Parsing started: {$filePath}");
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
            return in_array($rootName, ['GrandSmeta', 'Estimate', 'LocalEstimate', 'ObjectEstimate', 'GGE', 'Document']);
            
        } catch (\Exception $e) {
            return false;
        }
    }

    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), $this->getSupportedExtensions());
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

    public function getStream(string $filePath, array $options = []): Generator
    {
        Log::info("[GrandSmeta] Stream Parsing started: {$filePath}");
        $xml = $this->loadXML($filePath);
        
        $sections = [];
        $items = [];
        $this->processedSysIds = [];
        
        $estimateNode = $this->findEstimateNode($xml);
        
        // В XML ГрандСметы структура вложенная. 
        // Нам нужно выдать поток строк (секций и позиций) в том порядке, в котором они идут в файле.
        $allRows = [];
        $this->collectNodesRecursively($estimateNode, $allRows);
        
        foreach ($allRows as $row) {
             yield EstimateImportRowDTO::fromArray($row);
        }
    }

    private function collectNodesRecursively(\SimpleXMLElement $node, array &$allRows, string $parentPath = '', int $level = 0): void
    {
        // Перебираем детей в том порядке, в котором они идут в XML
        foreach ($node->children() as $child) {
            $name = strtolower($child->getName());
            
            if (in_array($name, ['itog', 'itogres', 'reportoptions', 'properties', 'header', 'signature'])) {
                continue;
            }

            if (in_array($name, ['section', 'razdel', 'chapter'])) {
                $this->processSectionNode($child, $allRows, $parentPath, $level);
            } elseif (in_array($name, ['position', 'item', 'poz'])) {
                $this->processItemNode($child, $allRows, $parentPath, $level);
            } else {
                // Идем глубage если это контейнеры типа Sections, Positions
                $this->collectNodesRecursively($child, $allRows, $parentPath, $level);
            }
        }
    }

    private function processSectionNode(\SimpleXMLElement $section, array &$allRows, string $parentPath, int $level): void
    {
        $num = (string)($section['Number'] ?? $section['Num'] ?? $section->Number ?? '');
        $name = (string)($section['Name'] ?? $section['Caption'] ?? $section->Name ?? $section->Caption ?? '');
        
        if (in_array(mb_strtolower(trim($name)), self::IGNORED_SECTIONS)) {
            return;
        }

        $currentPath = $parentPath ? "$parentPath.$num" : $num;
        
        $allRows[] = (new EstimateImportRowDTO(
            rowNumber: count($allRows) + 1, 
            sectionNumber: $num,
            itemName: $name ?: "Раздел $num",
            isSection: true,
            level: $level + 1,
            sectionPath: $currentPath
        ))->toArray();

        $this->collectNodesRecursively($section, $allRows, $currentPath, $level + 1);
    }

    private function processItemNode(\SimpleXMLElement $item, array &$allRows, string $parentPath, int $level): void
    {
        $sysId = (string)($item['SysID'] ?? $item->attributes()->SysID ?? '');
        $code = (string)($item['Justification'] ?? $item['Code'] ?? $item->Code ?? $item->Justification ?? '');
        
        if (!empty($sysId)) {
            if (in_array($sysId, $this->processedSysIds)) {
                return; 
            }
            $this->processedSysIds[] = $sysId;
        }

        $num = (string)($item['Number'] ?? $item['Num'] ?? $item->Number ?? '');
        $name = (string)($item['Name'] ?? $item['Caption'] ?? $item->Name ?? $item->Caption ?? '');
        $unit = (string)($item['Measure'] ?? $item['Unit'] ?? $item->Measure ?? $item->Unit ?? '');
        $qty = (float)($item['Quant'] ?? $item['Quantity'] ?? $item->Quant ?? $item->Quantity ?? 0);
        
        $dbFlags = (string)($item['DBFlags'] ?? $item->attributes()->DBFlags ?? '');
        if (str_contains($dbFlags, 'TechK')) {
            return;
        }

        $price = 0.0;
        if (isset($item->Price)) {
            $price = (float)($item->Price['Value'] ?? $item->Price);
        } elseif (isset($item->UnitCost)) {
            $price = (float)($item->UnitCost['Value'] ?? $item->UnitCost);
        }

        $total = (float)($item->Cost['Value'] ?? $item->Cost ?? ($qty * $price));

        $allRows[] = (new EstimateImportRowDTO(
            rowNumber: count($allRows) + 1,
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
            rawData: (array)$item
        ))->toArray();
        
        if (isset($item->Resources->Resource)) {
            foreach ($item->Resources->Resource as $res) {
                $this->processResourceNode($res, $allRows, $parentPath, $level);
            }
        }
    }

    private function processResourceNode(\SimpleXMLElement $res, array &$allRows, string $parentPath, int $level): void
    {
        $code = (string)($res['Code'] ?? $res->Code ?? '');
        $name = (string)($res['Name'] ?? $res->Name ?? '');
        $unit = (string)($res['Measure'] ?? $res->Measure ?? '');
        $qty = (float)($res['Quant'] ?? $res->Quantity ?? 0);
        $price = (float)($res['Price'] ?? $res->Price ?? 0);
        
        $allRows[] = (new EstimateImportRowDTO(
            rowNumber: count($allRows) + 1,
            sectionNumber: null,
            itemName: $name,
            unit: $unit,
            quantity: $qty,
            unitPrice: $price,
            code: $code,
            isSection: false,
            isSubItem: true,
            level: $level + 1,
            sectionPath: $parentPath,
            isNotAccounted: true,
            itemType: 'material'
        ))->toArray();
    }

    public function getPreview(string $filePath, int $limit = 20, array $options = []): array
    {
        $stream = $this->getStream($filePath, $options);
        $preview = [];
        
        foreach ($stream as $dto) {
            $preview[] = $dto;
            if (count($preview) >= $limit) {
                break;
            }
        }
        
        return $preview;
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

    // Metods parseNodeRecursively, processSection, processItem, processResource are replaced by collectNodesRecursively etc. above
    
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
