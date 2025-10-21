<?php

namespace App\BusinessModules\Features\BudgetEstimates\DTOs;

class EstimateImportResultDTO
{
    public function __construct(
        public ?int $estimateId,
        public int $itemsTotal,
        public int $itemsImported,
        public int $itemsSkipped,
        public int $sectionsCreated,
        public array $newWorkTypesCreated = [],
        public array $warnings = [],
        public array $errors = [],
        public ?int $processingTimeMs = null,
        public ?string $status = 'completed'
    ) {}
    
    public function toArray(): array
    {
        return [
            'estimate_id' => $this->estimateId,
            'items_total' => $this->itemsTotal,
            'items_imported' => $this->itemsImported,
            'items_skipped' => $this->itemsSkipped,
            'sections_created' => $this->sectionsCreated,
            'new_work_types_created' => $this->newWorkTypesCreated,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'processing_time_ms' => $this->processingTimeMs,
            'status' => $this->status,
        ];
    }
    
    public function isSuccessful(): bool
    {
        return empty($this->errors) && $this->estimateId !== null;
    }
    
    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }
    
    public function getSuccessRate(): float
    {
        if ($this->itemsTotal === 0) {
            return 0.0;
        }
        
        return ($this->itemsImported / $this->itemsTotal) * 100;
    }
    
    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }
    
    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }
}

