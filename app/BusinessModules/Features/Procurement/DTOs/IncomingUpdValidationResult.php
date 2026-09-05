<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\DTOs;

final readonly class IncomingUpdValidationResult
{
    /**
     * @param  array<string, string|null>  $seller
     * @param  array<string, string|null>  $buyer
     * @param  array<int, array<string, string|null>>  $items
     * @param  array<string, string|null>  $totals
     * @param  array<int, array{code: string}>  $errors
     * @param  array<int, array{code: string}>  $warnings
     */
    public function __construct(
        public ?string $fileId = null,
        public ?string $formatVersion = null,
        public ?string $function = null,
        public ?string $number = null,
        public ?string $date = null,
        public ?string $currencyCode = null,
        public array $seller = [],
        public array $buyer = [],
        public array $items = [],
        public array $totals = [],
        public array $errors = [],
        public array $warnings = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
