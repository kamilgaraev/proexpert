<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime;

final readonly class ImportDetectionResult
{
    /**
     * @param array<int, string> $indicators
     * @param array<int, array<string, mixed>> $candidates
     * @param array<string, mixed> $metadata
     * @param array<int, string> $warnings
     */
    public function __construct(
        public string $detectedType,
        public string $formatSlug,
        public string $label,
        public float $confidence,
        public bool $requiresConfirmation = false,
        public array $indicators = [],
        public array $candidates = [],
        public array $metadata = [],
        public array $warnings = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'detected_type' => $this->detectedType,
            'format_slug' => $this->formatSlug,
            'label' => $this->label,
            'confidence' => $this->confidence,
            'is_high_confidence' => $this->confidence >= 0.9,
            'requires_confirmation' => $this->requiresConfirmation,
            'indicators' => $this->indicators,
            'candidates' => $this->candidates,
            'metadata' => $this->metadata,
            'warnings' => $this->warnings,
        ];
    }
}
