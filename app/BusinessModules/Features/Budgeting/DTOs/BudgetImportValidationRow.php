<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class BudgetImportValidationRow
{
    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $normalized
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    public function __construct(
        public int $rowNumber,
        public array $raw,
        public array $normalized,
        public array $errors,
        public array $warnings
    ) {
    }

    public function status(): string
    {
        if ($this->errors !== []) {
            return 'invalid';
        }

        if ($this->warnings !== []) {
            return 'warning';
        }

        return 'valid';
    }
}
