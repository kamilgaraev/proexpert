<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

final readonly class ResidentialQuantityScenarioResult
{
    /** @param array<string, QuantityData> $quantities @param list<array{quantity_key: string, reason: string, package_key: string}> $omissions */
    public function __construct(
        public array $quantities,
        public array $omissions,
    ) {}
}
