<?php

namespace App\BusinessModules\Features\BudgetEstimates\DTOs;

class ImportMappingDTO
{
    public function __construct(
        public array $columnMappings,
        public array $detectedColumns,
        public array $sampleData,
        public ?int $headerRow = null,
        public ?int $dataStartRow = null
    ) {}
    
    public function toArray(): array
    {
        return [
            'column_mappings' => $this->columnMappings,
            'detected_columns' => $this->detectedColumns,
            'sample_data' => $this->sampleData,
            'header_row' => $this->headerRow,
            'data_start_row' => $this->dataStartRow,
        ];
    }
    
    public function getMapping(string $field): ?string
    {
        return $this->columnMappings[$field] ?? null;
    }
    
    public function setMapping(string $field, string $column): void
    {
        $this->columnMappings[$field] = $column;
    }
    
    public function hasMapping(string $field): bool
    {
        return isset($this->columnMappings[$field]) && !empty($this->columnMappings[$field]);
    }
    
    public function getRequiredFields(): array
    {
        return ['name', 'quantity', 'unit_price', 'unit'];
    }
    
    public function validateMappings(): array
    {
        $errors = [];
        $required = $this->getRequiredFields();
        
        foreach ($required as $field) {
            if (!$this->hasMapping($field)) {
                $errors[] = "Не указана колонка для обязательного поля: {$field}";
            }
        }
        
        $mapped = array_filter($this->columnMappings);
        $uniqueColumns = array_unique($mapped);
        
        if (count($mapped) !== count($uniqueColumns)) {
            $errors[] = "Одна колонка не может быть привязана к нескольким полям";
        }
        
        return $errors;
    }
    
    public function getConfidenceScore(): float
    {
        $totalFields = count($this->getRequiredFields()) + 2;
        $mappedFields = count(array_filter($this->columnMappings));
        
        $baseScore = ($mappedFields / $totalFields) * 100;
        
        $avgConfidence = 0;
        foreach ($this->detectedColumns as $column) {
            $avgConfidence += $column['confidence'] ?? 0;
        }
        $avgConfidence = count($this->detectedColumns) > 0 
            ? $avgConfidence / count($this->detectedColumns) 
            : 0;
        
        return ($baseScore * 0.7) + ($avgConfidence * 0.3);
    }
}

