<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\DTOs\Documents;

final readonly class DrawingAnalysisResultData
{
    public function __construct(
        public array $elements,
        public array $takeoffs,
        public array $summary = [],
    ) {}

    public function toArray(): array
    {
        return [
            'elements' => $this->elements,
            'takeoffs' => $this->takeoffs,
            'summary' => $this->summary,
        ];
    }
}
