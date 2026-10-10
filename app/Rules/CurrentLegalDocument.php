<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Legal\LegalDocumentService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class CurrentLegalDocument implements ValidationRule
{
    public function __construct(private readonly string $key) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! hash_equals(app(LegalDocumentService::class)->hash($this->key), $value)) {
            $fail(trans_message('legal.stale'));
        }
    }
}
