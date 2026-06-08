<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class BudgetImportPreviewResult
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $summary
     */
    public function __construct(
        public array $rows,
        public array $summary,
    ) {
    }

    public function hasErrors(): bool
    {
        return (int) ($this->summary['rows_invalid'] ?? 0) > 0;
    }
}
