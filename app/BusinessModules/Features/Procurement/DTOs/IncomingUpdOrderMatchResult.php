<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\DTOs;

final readonly class IncomingUpdOrderMatchResult
{
    /**
     * @param  array<int, array<string, mixed>>  $matchedItems
     * @param  array<int, array{code: string, line_number?: string|null}>  $errors
     * @param  array<int, array{code: string}>  $warnings
     */
    public function __construct(
        public array $matchedItems,
        public array $errors = [],
        public array $warnings = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
