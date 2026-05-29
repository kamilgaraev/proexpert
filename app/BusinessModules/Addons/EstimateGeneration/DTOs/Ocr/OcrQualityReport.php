<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr;

final readonly class OcrQualityReport
{
    /**
     * @param array<int, string> $flags
     * @param array<string, mixed> $metrics
     */
    public function __construct(
        public float $score,
        public string $level,
        public array $flags = [],
        public array $metrics = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'level' => $this->level,
            'flags' => $this->flags,
            'metrics' => $this->metrics,
        ];
    }
}
