<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr;

final readonly class DocumentProcessingResult
{
    /**
     * @param array<int, ExtractedDocumentFact> $facts
     * @param array<string, mixed> $factsSummary
     */
    public function __construct(
        public OcrRecognitionResult $recognition,
        public OcrQualityReport $quality,
        public array $facts = [],
        public array $factsSummary = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'recognition' => $this->recognition->toArray(),
            'quality' => $this->quality->toArray(),
            'facts' => array_map(
                static fn (ExtractedDocumentFact $fact): array => $fact->toArray(),
                $this->facts
            ),
            'facts_summary' => $this->factsSummary,
        ];
    }
}
