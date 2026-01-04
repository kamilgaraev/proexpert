<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers;

use App\BusinessModules\Features\BudgetEstimates\Contracts\EstimateImportParserInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use Illuminate\Support\Facades\Log;

class LocalEstimateCSVParser implements EstimateImportParserInterface
{
    private string $detectedEncoding = '';
    private string $detectedDelimiter = '';

    public function parse(string $filePath): EstimateImportDTO
    {
        $structure = $this->detectStructure($filePath);
        $rows = $this->extractRows($filePath, $structure);
        
        $sections = [];
        $items = [];
        
        foreach ($rows as $row) {
            if ($row->isSection) {
                $sections[] = $row->toArray();
            } else {
                $items[] = $row->toArray();
            }
        }
        
        // Calculate totals
        $totals = $this->calculateTotals($items);

        return new EstimateImportDTO(
            fileName: basename($filePath),
            fileSize: filesize($filePath),
            fileFormat: 'csv',
            sections: $sections,
            items: $items,
            totals: $totals,
            metadata: [
                'encoding' => $this->detectedEncoding,
                'delimiter' => $this->detectedDelimiter,
                'header_row' => $structure['header_row'],
            ]
        );
    }

    public function detectStructure(string $filePath): array
    {
        $this->detectedEncoding = $this->detectEncoding($filePath);
        $this->detectedDelimiter = $this->detectDelimiter($filePath);
        
        Log::info('[CSVParser] Properties detected', [
            'encoding' => $this->detectedEncoding,
            'delimiter' => $this->detectedDelimiter,
        ]);
        
        $handle = $this->openFile($filePath);
        if (!$handle) {
            throw new \Exception('Cannot open file: ' . $filePath);
        }
        
        $firstRows = [];
        for ($i = 0; $i < 20 && !feof($handle); $i++) {
            $line = fgets($handle);
            if ($line !== false) {
                $line = $this->convertEncoding($line);
                $firstRows[] = str_getcsv($line, $this->detectedDelimiter);
            }
        }
        fclose($handle);
        
        // Heuristic: Header row usually has "Наименование" or "Code"
        $headerRow = $this->findHeaderRow($firstRows);
        $headers = $firstRows[$headerRow] ?? [];
        $columnMapping = $this->mapColumns($headers);
        
        return [
            'header_row' => $headerRow,
            'encoding' => $this->detectedEncoding,
            'delimiter' => $this->detectedDelimiter,
            'raw_headers' => $headers,
            'column_mapping' => $columnMapping,
            'detected_columns' => $this->formatDetectedColumns($headers, $columnMapping),
        ];
    }

    private function findHeaderRow(array $rows): int
    {
        $bestRow = 0;
        $maxScore = 0;

        foreach ($rows as $index => $row) {
            $score = 0;
            $rowStr = mb_strtolower(implode(' ', $row));
            
            // Keywords that strongly indicate a header
            if (str_contains($rowStr, 'наименование')) $score += 10;
            if (str_contains($rowStr, 'ед. изм') || str_contains($rowStr, 'ед.изм')) $score += 5;
            if (str_contains($rowStr, 'кол-во') || str_contains($rowStr, 'количество')) $score += 5;
            if (str_contains($rowStr, 'цена') || str_contains($rowStr, 'стоимость')) $score += 5;
            if (str_contains($rowStr, 'код') || str_contains($rowStr, 'обоснование')) $score += 5;
            
            // Penalize empty rows
            if (empty(trim($rowStr))) $score = -1;

            if ($score > $maxScore) {
                $maxScore = $score;
                $bestRow = $index;
            }
        }
        
        // Fallback: row with most columns
        if ($maxScore < 5) {
             $maxCols = 0;
             foreach ($rows as $index => $row) {
                 $cols = count(array_filter($row, fn($v) => !empty(trim($v))));
                 if ($cols > $maxCols) {
                     $maxCols = $cols;
                     $bestRow = $index;
                 }
             }
        }

        return $bestRow;
    }

    public function validateFile(string $filePath): bool
    {
        if (!file_exists($filePath)) return false;
        
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext !== 'csv' && $ext !== 'txt') return false; // Accept .txt as CSV often comes as .txt
        
        // Check if readable
        $handle = @fopen($filePath, 'r');
        if ($handle) {
            fclose($handle);
            return true;
        }
        return false;
    }

    public function getSupportedExtensions(): array
    {
        return ['csv', 'txt'];
    }

    public function getHeaderCandidates(): array
    {
        return [];
    }

    public function detectStructureFromRow(string $filePath, int $headerRow): array
    {
        $this->detectedEncoding = $this->detectEncoding($filePath);
        $this->detectedDelimiter = $this->detectDelimiter($filePath);
        
        $handle = $this->openFile($filePath);
        $headers = [];
        
        for ($i = 0; $i <= $headerRow && !feof($handle); $i++) {
            $line = fgets($handle);
            if ($i === $headerRow && $line !== false) {
                $line = $this->convertEncoding($line);
                $headers = str_getcsv($line, $this->detectedDelimiter);
            }
        }
        fclose($handle);
        
        $columnMapping = $this->mapColumns($headers);
        
        return [
            'header_row' => $headerRow,
            'encoding' => $this->detectedEncoding,
            'delimiter' => $this->detectedDelimiter,
            'raw_headers' => $headers,
            'column_mapping' => $columnMapping,
            'detected_columns' => $this->formatDetectedColumns($headers, $columnMapping),
        ];
    }

    public function readContent(string $filePath, int $maxRows = 100)
    {
        $handle = $this->openFile($filePath);
        $content = '';
        for ($i = 0; $i < $maxRows && !feof($handle); $i++) {
            $line = fgets($handle);
            if ($line !== false) {
                $content .= $this->convertEncoding($line);
            }
        }
        fclose($handle);
        return $content;
    }

    private function openFile(string $filePath)
    {
        return fopen($filePath, 'r');
    }

    private function detectEncoding(string $filePath): string
    {
        $content = file_get_contents($filePath, false, null, 0, 4096);
        if ($content === false) return 'UTF-8';
        
        if (str_starts_with($content, "\xEF\xBB\xBF")) return 'UTF-8';
        
        // Try common Russian encodings
        $encodings = ['UTF-8', 'CP1251', 'KOI8-R', 'ISO-8859-5'];
        $detected = mb_detect_encoding($content, $encodings, true);
        
        return $detected ?: 'UTF-8';
    }

    private function detectDelimiter(string $filePath): string
    {
        $handle = $this->openFile($filePath);
        $line = fgets($handle);
        fclose($handle);
        
        if (!$line) return ';'; // Default to semicolon for Russian CSVs
        
        $line = $this->convertEncoding($line);
        
        $delimiters = [';' => 0, ',' => 0, "\t" => 0, '|' => 0];
        foreach ($delimiters as $sep => $count) {
            $delimiters[$sep] = substr_count($line, $sep);
        }
        
        arsort($delimiters);
        return array_key_first($delimiters);
    }

    private function convertEncoding(string $text): string
    {
        if (empty($this->detectedEncoding) || $this->detectedEncoding === 'UTF-8') return $text;
        return mb_convert_encoding($text, 'UTF-8', $this->detectedEncoding);
    }

    private function extractRows(string $filePath, array $structure): array
    {
        $handle = $this->openFile($filePath);
        $rows = [];
        $lineNum = 0;
        $headerRow = $structure['header_row'];
        $mapping = $structure['column_mapping'];
        
        while (($line = fgets($handle)) !== false) {
            $lineNum++;
            if ($lineNum <= $headerRow + 1) continue;
            
            $line = $this->convertEncoding($line);
            // Skip empty lines
            if (trim($line) === '') continue;
            
            $data = str_getcsv($line, $this->detectedDelimiter);
            // Fix: sometimes csv row has fewer columns than mapping implies
            
            $rowData = $this->mapRowData($data, $mapping);
            if ($this->isEmptyRow($rowData)) continue;
            
            $isSection = $this->isSection($rowData);
            
            $rows[] = new EstimateImportRowDTO(
                rowNumber: $lineNum,
                sectionNumber: $rowData['section_number'],
                itemName: $rowData['name'] ?: ($isSection ? 'Раздел' : '[Без названия]'),
                unit: $rowData['unit'],
                quantity: $rowData['quantity'],
                unitPrice: $rowData['unit_price'],
                code: $rowData['code'],
                isSection: $isSection,
                level: $this->calculateLevel($rowData['section_number']),
                sectionPath: null,
                isNotAccounted: false // TODO: Add logic if CSV has specific flag
            );
        }
        fclose($handle);
        return $rows;
    }

    private function mapRowData(array $csvRow, array $mapping): array
    {
        $result = [
            'section_number' => null, 'name' => null, 'unit' => null,
            'quantity' => null, 'unit_price' => null, 'code' => null
        ];
        
        foreach ($mapping as $field => $index) {
            if ($index !== null && isset($csvRow[$index])) {
                $val = trim($csvRow[$index]);
                if (in_array($field, ['quantity', 'unit_price'])) {
                    $val = $this->parseNumber($val);
                }
                $result[$field] = $val;
            }
        }
        return $result;
    }

    private function mapColumns(array $headers): array
    {
        $mapping = [
            'section_number' => null, 'name' => null, 'unit' => null,
            'quantity' => null, 'unit_price' => null, 'code' => null
        ];
        
        // Enhanced keywords
        $keywords = [
            'section_number' => ['№', 'номер', 'п/п', 'поз.', '№ п/п'],
            'name' => ['наименование', 'название', 'работ', 'затрат', 'описание'],
            'unit' => ['ед.', 'изм', 'единица'],
            'quantity' => ['кол-во', 'количество', 'объем'],
            'unit_price' => ['цена', 'стоимость', 'расценка', 'за ед'],
            'code' => ['код', 'шифр', 'обоснование', 'артикул'],
        ];
        
        foreach ($headers as $index => $header) {
            $h = mb_strtolower(trim($header));
            foreach ($keywords as $field => $keys) {
                if ($mapping[$field] === null) {
                    foreach ($keys as $key) {
                        if (str_contains($h, $key)) {
                            $mapping[$field] = $index;
                            break 2;
                        }
                    }
                }
            }
        }
        return $mapping;
    }

    private function formatDetectedColumns(array $headers, array $mapping): array
    {
        $detected = [];
        $flip = array_flip(array_filter($mapping));
        foreach ($headers as $i => $h) {
            $field = $flip[$i] ?? null;
            $detected[$i] = [
                'field' => $field,
                'header' => $h,
                'confidence' => $field ? 0.9 : 0.0
            ];
        }
        return $detected;
    }

    private function parseNumber($val)
    {
        if (is_numeric($val)) return (float)$val;
        // Handle Russian format: 1 234,56 -> 1234.56
        $val = str_replace([' ', "\xC2\xA0"], '', $val);
        $val = str_replace(',', '.', $val);
        return (float)$val;
    }

    private function isEmptyRow(array $row): bool
    {
        return empty($row['name']) && empty($row['code']) && empty($row['quantity']);
    }

    private function isSection(array $row): bool
    {
        // Logic: has name, no quantity/price, implies section
        return !empty($row['name']) && empty($row['quantity']) && empty($row['unit_price']);
    }

    private function calculateLevel($num): int
    {
        if (!$num) return 1;
        return substr_count($num, '.') + 1;
    }

    private function calculateTotals(array $items): array
    {
        $sum = 0; $qty = 0;
        foreach ($items as $item) {
            $sum += ($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0);
            $qty += ($item['quantity'] ?? 0);
        }
        return ['total_amount' => $sum, 'total_quantity' => $qty, 'items_count' => count($items)];
    }
}
