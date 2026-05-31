<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime;

final readonly class ImportValidationResult
{
    /**
     * @param array<int, array<string, mixed>|string> $errors
     * @param array<int, array<string, mixed>|string> $warnings
     * @param array<string, mixed> $summary
     */
    public function __construct(
        public array $errors = [],
        public array $warnings = [],
        public array $summary = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_valid' => $this->isValid(),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'summary' => $this->summary,
        ];
    }
}
