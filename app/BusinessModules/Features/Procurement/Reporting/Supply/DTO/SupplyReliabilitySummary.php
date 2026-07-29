<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\DTO;

final readonly class SupplyReliabilitySummary
{
    public function __construct(
        public int $otifNumerator,
        public int $eligibleDenominator,
        public ?string $otifRatio,
        public string $quantityOtifNumerator = '0.000',
        public string $quantityOtifDenominator = '0.000',
        public ?string $quantityOtifRatio = null,
        public ?int $valueOtifNumeratorMinor = null,
        public ?int $valueOtifDenominatorMinor = null,
        public ?string $valueOtifRatio = null,
        public array $valueOtifByBasis = [],
    ) {}
}
