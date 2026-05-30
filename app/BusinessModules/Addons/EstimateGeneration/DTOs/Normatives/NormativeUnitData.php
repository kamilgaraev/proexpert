<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives;

final readonly class NormativeUnitData
{
    public function __construct(
        public string $raw,
        public string $normalized,
        public string $dimension,
        public string $baseUnit,
        public float $multiplier,
    ) {}

    public function isKnown(): bool
    {
        return $this->dimension !== '' && $this->baseUnit !== '';
    }

    public function compatibleWith(self $other): bool
    {
        return $this->isKnown()
            && $other->isKnown()
            && $this->dimension === $other->dimension
            && $this->baseUnit === $other->baseUnit;
    }
}
