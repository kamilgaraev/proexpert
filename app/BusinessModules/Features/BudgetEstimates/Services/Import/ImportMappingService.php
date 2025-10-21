<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\DTOs\ImportMappingDTO;
use Illuminate\Support\Facades\Cache;

class ImportMappingService
{
    private array $columnKeywords = [
        'name' => ['наименование', 'название', 'работа', 'позиция', 'наименование работ'],
        'unit' => ['ед.изм', 'единица', 'ед', 'измерение', 'ед. изм'],
        'quantity' => ['количество', 'кол-во', 'объем', 'кол', 'объём'],
        'unit_price' => ['цена', 'стоимость', 'расценка', 'цена за ед', 'стоимость единицы'],
        'code' => ['код', 'шифр', 'обоснование', 'гэсн', 'фер', 'шифр расценки'],
        'section_number' => ['№', 'номер', '№ п/п', 'п/п', 'n'],
    ];

    public function detectColumns(array $headerRow, array $sampleRows, ?int $headerRowNum = null, ?int $dataStartRow = null): ImportMappingDTO
    {
        $detectedColumns = [];
        $columnMappings = [];
        
        foreach ($headerRow as $columnLetter => $headerText) {
            $normalized = mb_strtolower(trim($headerText));
            
            $bestMatch = null;
            $bestConfidence = 0;
            
            foreach ($this->columnKeywords as $field => $keywords) {
                foreach ($keywords as $keyword) {
                    if ($normalized === $keyword) {
                        $bestMatch = $field;
                        $bestConfidence = 1.0;
                        break 2;
                    }
                    
                    if (str_contains($normalized, $keyword)) {
                        $confidence = mb_strlen($keyword) / max(mb_strlen($normalized), 1);
                        if ($confidence > $bestConfidence) {
                            $bestMatch = $field;
                            $bestConfidence = $confidence;
                        }
                    }
                }
            }
            
            if ($bestMatch !== null && $bestConfidence > 0.5) {
                $detectedColumns[$columnLetter] = [
                    'field' => $bestMatch,
                    'confidence' => $bestConfidence,
                    'header' => $headerText,
                ];
                
                if (!isset($columnMappings[$bestMatch]) || $bestConfidence > ($detectedColumns[$columnMappings[$bestMatch]]['confidence'] ?? 0)) {
                    $columnMappings[$bestMatch] = $columnLetter;
                }
            }
        }
        
        return new ImportMappingDTO(
            columnMappings: $columnMappings,
            detectedColumns: $detectedColumns,
            sampleData: array_slice($sampleRows, 0, 5),
            headerRow: $headerRowNum,
            dataStartRow: $dataStartRow
        );
    }

    public function mapColumns(array $userMapping, array $headerRow, array $sampleRows): ImportMappingDTO
    {
        $detectedColumns = [];
        
        foreach ($userMapping as $field => $columnLetter) {
            if (isset($headerRow[$columnLetter])) {
                $detectedColumns[$columnLetter] = [
                    'field' => $field,
                    'confidence' => 1.0,
                    'header' => $headerRow[$columnLetter],
                ];
            }
        }
        
        return new ImportMappingDTO(
            columnMappings: $userMapping,
            detectedColumns: $detectedColumns,
            sampleData: array_slice($sampleRows, 0, 5)
        );
    }

    public function validateMapping(ImportMappingDTO $mapping): array
    {
        return $mapping->validateMappings();
    }

    public function getColumnKeywords(): array
    {
        return $this->columnKeywords;
    }

    public function saveTemplate(int $organizationId, string $name, ImportMappingDTO $mapping): void
    {
        $cacheKey = "estimate_import_template:{$organizationId}:{$name}";
        Cache::put($cacheKey, $mapping->toArray(), now()->addMonths(6));
    }

    public function loadTemplate(int $organizationId, string $name): ?ImportMappingDTO
    {
        $cacheKey = "estimate_import_template:{$organizationId}:{$name}";
        $data = Cache::get($cacheKey);
        
        if ($data === null) {
            return null;
        }
        
        return new ImportMappingDTO(
            columnMappings: $data['column_mappings'],
            detectedColumns: $data['detected_columns'],
            sampleData: $data['sample_data'],
            headerRow: $data['header_row'] ?? null,
            dataStartRow: $data['data_start_row'] ?? null
        );
    }

    public function listTemplates(int $organizationId): array
    {
        return [];
    }

    public function suggestMapping(array $headerRow): array
    {
        $suggestions = [];
        
        foreach ($headerRow as $columnLetter => $headerText) {
            $normalized = mb_strtolower(trim($headerText));
            
            $matches = [];
            foreach ($this->columnKeywords as $field => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($normalized, $keyword)) {
                        $confidence = $this->calculateConfidence($normalized, $keyword);
                        $matches[] = [
                            'field' => $field,
                            'confidence' => $confidence,
                        ];
                    }
                }
            }
            
            if (!empty($matches)) {
                usort($matches, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
                $suggestions[$columnLetter] = $matches;
            }
        }
        
        return $suggestions;
    }

    private function calculateConfidence(string $text, string $keyword): float
    {
        if ($text === $keyword) {
            return 1.0;
        }
        
        if (str_starts_with($text, $keyword)) {
            return 0.9;
        }
        
        if (str_contains($text, $keyword)) {
            return mb_strlen($keyword) / max(mb_strlen($text), 1);
        }
        
        return 0.0;
    }
}

