<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime;

final readonly class ImportPreviewResult
{
    /**
     * @param array<int, array<string, mixed>> $sections
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $totals
     * @param array<string, mixed> $validation
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $quality
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $formatSlug,
        public array $sections = [],
        public array $items = [],
        public array $totals = [],
        public array $validation = [],
        public array $summary = [],
        public array $quality = [],
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format_slug' => $this->formatSlug,
            'sections' => $this->sections,
            'items' => $this->items,
            'totals' => $this->totals,
            'validation' => $this->validation,
            'summary' => $this->summary,
            'quality' => $this->quality,
            'metadata' => $this->metadata,
        ];
    }
}
