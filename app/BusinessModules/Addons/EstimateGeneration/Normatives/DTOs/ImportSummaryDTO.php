<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs;

final readonly class ImportSummaryDTO
{
    public function __construct(
        public int $processed = 0,
        public int $imported = 0,
        public int $skipped = 0,
        public int $failed = 0,
        public array $errors = [],
        public array $metadata = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'processed' => $this->processed,
            'imported' => $this->imported,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'errors' => $this->errors,
            'metadata' => $this->metadata,
        ];
    }
}
