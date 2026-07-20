<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

use RuntimeException;

final class MissingRegionalPrice extends RuntimeException
{
    private function __construct(
        public readonly int $priceId,
        public readonly string $reason,
    ) {
        parent::__construct("Exact regional price is missing for resource price {$priceId} ({$reason}).");
    }

    public static function forResource(int $priceId, string $reason = 'missing_regional_price'): self
    {
        return new self($priceId, $reason);
    }
}
