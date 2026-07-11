<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

final readonly class AiCost
{
    public function __construct(
        public ?string $amount,
        public ?string $currency,
        public string $pricingStatus,
    ) {}
}
