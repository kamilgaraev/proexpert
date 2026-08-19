<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

final readonly class VisualObjectClassification
{
    public function __construct(
        public string $objectType,
        public ?string $limitationCode = null,
    ) {}
}
