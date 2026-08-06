<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\DTO;

use App\Enums\CurrencyCode;
use InvalidArgumentException;

final readonly class ApprovedAcceptanceRate
{
    public function __construct(
        public int $minor,
        public string $currency,
        public string $source,
    ) {
        if ($minor < 0
            || CurrencyCode::tryFrom($currency) === null
            || trim($source) === ''
        ) {
            throw new InvalidArgumentException('approved_acceptance_rate_invalid');
        }
    }
}
