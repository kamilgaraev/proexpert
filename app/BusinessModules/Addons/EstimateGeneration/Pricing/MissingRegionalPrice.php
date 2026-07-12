<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

use RuntimeException;

final class MissingRegionalPrice extends RuntimeException
{
    public static function forResource(int $priceId): self
    {
        return new self("Exact regional price is missing for resource price {$priceId}.");
    }
}
