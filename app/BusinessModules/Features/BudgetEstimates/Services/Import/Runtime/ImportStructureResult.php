<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime;

final readonly class ImportStructureResult
{
    /**
     * @param array<string, string> $columnMapping
     * @param array<string, string> $detectedColumns
     * @param array<int, mixed> $rawHeaders
     * @param array<int, array<int|string, mixed>> $sampleRows
     * @param array<int, mixed> $headerCandidates
     * @param array<int|string, mixed> $rowStyles
     * @param array<int, string> $warnings
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $formatSlug,
        public ?int $headerRow = null,
        public array $columnMapping = [],
        public array $detectedColumns = [],
        public array $rawHeaders = [],
        public array $sampleRows = [],
        public array $headerCandidates = [],
        public array $rowStyles = [],
        public array $warnings = [],
        public array $metadata = [],
        public bool $aiMappingApplied = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format_slug' => $this->formatSlug,
            'format' => $this->formatSlug,
            'header_row' => $this->headerRow,
            'column_mapping' => $this->columnMapping,
            'detected_columns' => $this->detectedColumns,
            'raw_headers' => $this->rawHeaders,
            'sample_rows' => $this->sampleRows,
            'header_candidates' => $this->headerCandidates,
            'row_styles' => $this->rowStyles,
            'warnings' => $this->warnings,
            'metadata' => $this->metadata,
            'ai_mapping_applied' => $this->aiMappingApplied,
        ];
    }
}
