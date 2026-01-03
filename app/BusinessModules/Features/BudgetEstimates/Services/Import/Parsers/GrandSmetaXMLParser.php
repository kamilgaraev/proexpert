<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers;

use App\BusinessModules\Features\BudgetEstimates\Contracts\EstimateImportParserInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use Illuminate\Support\Facades\Log;

class GrandSmetaXMLParser implements EstimateImportParserInterface
{
    public function parse(string $filePath): EstimateImportDTO
    {
        $xml = $this->loadXML($filePath);
        
        $sections = [];
        $items = [];
        
        // Парсим разделы и позиции
        $this->parseEstimateNode($xml, $sections, $items);
        
        // Получаем метаданные из заголовка
        $metadata = $this->parseMetadata($xml);
        $metadata['estimate_type'] = 'grandsmeta'; // Автоматически устанавливаем тип
        
        return new EstimateImportDTO(
            fileName: basename($filePath),
            fileSize: filesize($filePath),
            fileFormat: 'grandsmeta_xml',
            sections: $sections,
            items: $items,
            totals: [
                'total_amount' => 0,
                'total_quantity' => 0,
                'items_count' => count($items),
            ],
            metadata: $metadata,
            estimateType: 'grandsmeta', // Устанавливаем тип сметы
            typeConfidence: 100.0 // Для XML ГрандСметы confidence = 100%
        );
    }

    public function detectStructure(string $filePath): array
    {
        $xml = $this->loadXML($filePath);
        
        // Извлекаем информацию о колонках из структуры XML
        $columns = $this->detectColumns($xml);
        
        return [
            'format' => 'grandsmeta_xml',
            'detected_columns' => $columns,
            'raw_headers' => [],
            'header_row' => null, // XML не имеет концепции строки заголовков
            'column_mapping' => [
                'section_number' => 'number',
                'name' => 'name',
                'unit' => 'unit',
                'quantity' => 'quantity',
                'unit_price' => 'unitPrice',
                'code' => 'code',
            ],
        ];
    }

    public function validateFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($extension !== 'xml') {
            return false;
        }
        
        try {
            $xml = simplexml_load_file($filePath);
            if ($xml === false) {
                return false;
            }
            
            // Проверяем что это файл ГРАНД-Смета
            $rootName = $xml->getName();
            return in_array($rootName, ['GrandSmeta', 'estimate', 'Estimate', 'LocalEstimate']);
            
        } catch (\Exception $e) {
            Log::error('[GrandSmetaXML] Validation failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getSupportedExtensions(): array
    {
        return ['xml'];
    }

    public function getHeaderCandidates(): array
    {
        // XML не имеет концепции заголовков - структура фиксирована
        return [];
    }

    /**
     * Читать содержимое файла для детекции типа (без полного парсинга)
     * 
     * @param string $filePath Путь к файлу
     * @param int $maxRows Максимальное количество строк для чтения (игнорируется для XML)
     * @return mixed SimpleXMLElement для XML
     */
    public function readContent(string $filePath, int $maxRows = 100)
    {
        return $this->loadXML($filePath);
    }

    public function detectStructureFromRow(string $filePath, int $headerRow): array
    {
        // Для XML этот метод не применим
        return $this->detectStructure($filePath);
    }

    /**
     * Загружает XML файл
     */
    private function loadXML(string $filePath): \SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        
        $xml = simplexml_load_file($filePath, 'SimpleXMLElement', LIBXML_NOCDATA);
        
        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            
            $errorMessages = array_map(fn($e) => $e->message, $errors);
            throw new \Exception('Failed to parse XML: ' . implode(', ', $errorMessages));
        }
        
        return $xml;
    }

    /**
     * Парсит метаданные из заголовка файла
     */
    private function parseMetadata(\SimpleXMLElement $xml): array
    {
        $metadata = [
            'format' => 'grandsmeta_xml',
            'program_version' => null,
            'estimate_name' => null,
            'estimate_code' => null,
            'base_date' => null,
            'current_date' => null,
        ];
        
        // Извлекаем метаданные из атрибутов и элементов
        if (isset($xml['version'])) {
            $metadata['program_version'] = (string) $xml['version'];
        }
        
        if (isset($xml->header)) {
            $header = $xml->header;
            
            $metadata['estimate_name'] = isset($header->name) ? (string) $header->name : null;
            $metadata['estimate_code'] = isset($header->code) ? (string) $header->code : null;
            $metadata['base_date'] = isset($header->baseDate) ? (string) $header->baseDate : null;
            $metadata['current_date'] = isset($header->currentDate) ? (string) $header->currentDate : null;
        }
        
        return $metadata;
    }

    /**
     * Определяет колонки из структуры XML
     */
    private function detectColumns(\SimpleXMLElement $xml): array
    {
        // Стандартные колонки для ГРАНД-Смета
        return [
            'number' => [
                'field' => 'section_number',
                'header' => '№ п/п',
                'confidence' => 1.0,
            ],
            'code' => [
                'field' => 'code',
                'header' => 'Обоснование',
                'confidence' => 1.0,
            ],
            'name' => [
                'field' => 'name',
                'header' => 'Наименование',
                'confidence' => 1.0,
            ],
            'unit' => [
                'field' => 'unit',
                'header' => 'Ед. изм.',
                'confidence' => 1.0,
            ],
            'quantity' => [
                'field' => 'quantity',
                'header' => 'Количество',
                'confidence' => 1.0,
            ],
            'unitPrice' => [
                'field' => 'unit_price',
                'header' => 'Цена за ед.',
                'confidence' => 1.0,
            ],
        ];
    }

    /**
     * Рекурсивно парсит узлы estimate
     */
    private function parseEstimateNode(\SimpleXMLElement $node, array &$sections, array &$items, string $parentPath = '', int $level = 0): void
    {
        // Ищем элементы section или item
        if (isset($node->sections)) {
            foreach ($node->sections->section as $section) {
                $this->parseSection($section, $sections, $items, $parentPath, $level);
            }
        }
        
        if (isset($node->items)) {
            foreach ($node->items->item as $item) {
                $this->parseItem($item, $items, $parentPath);
            }
        }
        
        // Альтернативный формат - прямые дочерние элементы
        foreach ($node->children() as $child) {
            $childName = $child->getName();
            
            if ($childName === 'section') {
                $this->parseSection($child, $sections, $items, $parentPath, $level);
            } elseif ($childName === 'item') {
                $this->parseItem($child, $items, $parentPath);
            }
        }
    }

    /**
     * Парсит раздел
     */
    private function parseSection(\SimpleXMLElement $section, array &$sections, array &$items, string $parentPath, int $level): void
    {
        $number = isset($section['number']) ? (string) $section['number'] : '';
        $name = isset($section['name']) ? (string) $section['name'] : '';
        
        if (isset($section->name)) {
            $name = (string) $section->name;
        }
        
        $sectionPath = $parentPath ? "$parentPath.$number" : $number;
        
        $sectionDTO = new EstimateImportRowDTO(
            rowNumber: 0, // Will be set during processing
            sectionNumber: $number,
            itemName: !empty($name) ? $name : 'Раздел ' . $number,
            unit: null,
            quantity: null,
            unitPrice: null,
            code: isset($section['code']) ? (string) $section['code'] : null,
            isSection: true,
            level: $level,
            sectionPath: $sectionPath,
        );
        
        $sections[] = $sectionDTO->toArray();
        
        // Рекурсивно обрабатываем вложенные разделы и позиции
        $this->parseEstimateNode($section, $sections, $items, $sectionPath, $level + 1);
    }

    /**
     * Парсит позицию сметы
     */
    private function parseItem(\SimpleXMLElement $item, array &$items, string $sectionPath): void
    {
        $number = isset($item['number']) ? (string) $item['number'] : '';
        $name = isset($item['name']) ? (string) $item['name'] : '';
        $code = isset($item['code']) ? (string) $item['code'] : '';
        $unit = isset($item['unit']) ? (string) $item['unit'] : '';
        $quantity = isset($item['quantity']) ? (float) $item['quantity'] : 0;
        $unitPrice = isset($item['unitPrice']) ? (float) $item['unitPrice'] : 0;
        
        // Альтернативные имена элементов
        if (isset($item->name)) {
            $name = (string) $item->name;
        }
        if (isset($item->code)) {
            $code = (string) $item->code;
        }
        if (isset($item->unit)) {
            $unit = (string) $item->unit;
        }
        if (isset($item->quantity)) {
            $quantity = (float) $item->quantity;
        }
        if (isset($item->unitPrice)) {
            $unitPrice = (float) $item->unitPrice;
        }
        
        $itemDTO = new EstimateImportRowDTO(
            rowNumber: 0, // Will be set during processing
            sectionNumber: null,
            itemName: !empty($name) ? $name : '[Без наименования]',
            unit: $unit ?: null,
            quantity: $quantity > 0 ? $quantity : null,
            unitPrice: $unitPrice > 0 ? $unitPrice : null,
            code: $code ?: null,
            isSection: false,
            level: 0,
            sectionPath: $sectionPath,
        );
        
        $items[] = $itemDTO->toArray();
    }
}

