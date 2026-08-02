<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\DTO;

use InvalidArgumentException;

final readonly class ApprovedAcceptanceRate
{
    public function __construct(
        public int $minor,
        public string $currency,
        public string $source,
    ) {
        if ($minor < 0
            || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || trim($source) === ''
        ) {
            throw new InvalidArgumentException('approved_acceptance_rate_invalid');
        }
    }
}
