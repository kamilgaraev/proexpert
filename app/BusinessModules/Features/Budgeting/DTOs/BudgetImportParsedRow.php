<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class BudgetImportParsedRow
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public int $rowNumber,
        public array $raw
    ) {
    }
}
