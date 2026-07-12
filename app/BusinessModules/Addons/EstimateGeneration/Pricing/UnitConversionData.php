<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

final readonly class UnitConversionData
{
    public function __construct(
        public int $id,
        public string $fromUnit,
        public string $toUnit,
        public string $factor,
        public int $version,
        public string $fingerprint,
    ) {}
}
