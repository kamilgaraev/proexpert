<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr;

final readonly class ExtractedDocumentFact
{
    /**
     * @param array<string, mixed> $sourceRef
     * @param array<string, mixed> $normalizedPayload
     */
    public function __construct(
        public string $factType,
        public string $label,
        public float $confidence,
        public ?string $scopeKey = null,
        public ?string $valueText = null,
        public ?float $valueNumber = null,
        public ?string $unit = null,
        public array $sourceRef = [],
        public array $normalizedPayload = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fact_type' => $this->factType,
            'scope_key' => $this->scopeKey,
            'label' => $this->label,
            'value_text' => $this->valueText,
            'value_number' => $this->valueNumber,
            'unit' => $this->unit,
            'confidence' => $this->confidence,
            'source_ref' => $this->sourceRef,
            'normalized_payload' => $this->normalizedPayload,
        ];
    }
}
