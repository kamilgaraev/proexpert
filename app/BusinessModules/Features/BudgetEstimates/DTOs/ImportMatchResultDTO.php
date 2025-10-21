<?php

namespace App\BusinessModules\Features\BudgetEstimates\DTOs;

use App\Models\WorkType;

class ImportMatchResultDTO
{
    public function __construct(
        public string $importedText,
        public ?WorkType $matchedWorkType,
        public int $confidence,
        public array $alternativeMatches = [],
        public bool $shouldCreate = false,
        public ?string $matchMethod = null,
        public ?array $metadata = null
    ) {}
    
    public function toArray(): array
    {
        return [
            'imported_text' => $this->importedText,
            'matched_work_type' => $this->matchedWorkType ? [
                'id' => $this->matchedWorkType->id,
                'name' => $this->matchedWorkType->name,
                'code' => $this->matchedWorkType->code,
                'unit' => $this->matchedWorkType->unit,
            ] : null,
            'confidence' => $this->confidence,
            'alternative_matches' => array_map(function($match) {
                return [
                    'id' => $match['work_type']->id,
                    'name' => $match['work_type']->name,
                    'confidence' => $match['confidence'],
                    'method' => $match['method'] ?? null,
                ];
            }, $this->alternativeMatches),
            'should_create' => $this->shouldCreate,
            'match_method' => $this->matchMethod,
            'metadata' => $this->metadata,
        ];
    }
    
    public function hasConfidentMatch(int $threshold = 85): bool
    {
        return $this->matchedWorkType !== null && $this->confidence >= $threshold;
    }
    
    public function getBestMatch(): ?array
    {
        if ($this->matchedWorkType) {
            return [
                'work_type' => $this->matchedWorkType,
                'confidence' => $this->confidence,
                'method' => $this->matchMethod,
            ];
        }
        
        if (!empty($this->alternativeMatches)) {
            return $this->alternativeMatches[0];
        }
        
        return null;
    }
}

