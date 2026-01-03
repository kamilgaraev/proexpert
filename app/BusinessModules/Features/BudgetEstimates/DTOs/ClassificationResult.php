<?php

namespace App\BusinessModules\Features\BudgetEstimates\DTOs;

class ClassificationResult
{
    public function __construct(
        public string $type,
        public float $confidenceScore,
        public string $source,
        public array $metadata = []
    ) {}

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'confidence_score' => $this->confidenceScore,
            'source' => $this->source,
            'metadata' => $this->metadata,
        ];
    }
}
