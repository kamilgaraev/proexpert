<?php

namespace App\BusinessModules\Addons\AIEstimates\DTOs;

class AIEstimateResponseDTO
{
    public function __construct(
        public readonly int $generationId,
        public readonly array $sections,
        public readonly array $items,
        public readonly float $totalCost,
        public readonly float $averageConfidence,
        public readonly int $tokensUsed,
        public readonly ?float $processingTime = null,
    ) {}

    public static function fromGenerationResult(
        int $generationId,
        array $estimateData,
        int $tokensUsed,
        ?float $processingTime = null
    ): self {
        return new self(
            generationId: $generationId,
            sections: $estimateData['sections'] ?? [],
            items: $estimateData['items'] ?? [],
            totalCost: $estimateData['total_cost'] ?? 0.0,
            averageConfidence: $estimateData['average_confidence'] ?? 0.0,
            tokensUsed: $tokensUsed,
            processingTime: $processingTime,
        );
    }

    public function toArray(): array
    {
        return [
            'generation_id' => $this->generationId,
            'sections' => $this->sections,
            'items' => $this->items,
            'total_cost' => $this->totalCost,
            'average_confidence' => $this->averageConfidence,
            'tokens_used' => $this->tokensUsed,
            'processing_time' => $this->processingTime,
        ];
    }
}
