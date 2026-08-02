<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\DTO;

final readonly class SupplyLineMetric
{
    public function __construct(
        public string $netReceivedQuantity,
        public bool $eligible,
        public bool $onTime,
        public bool $inFull,
        public bool $otif,
        public int $otifNumerator,
        public int $eligibleDenominator,
        public bool $mature,
        public bool $stableInFull,
        public string $quantityOtifNumerator = '0.000',
        public string $quantityOtifDenominator = '0.000',
        public ?int $valueOtifNumeratorMinor = null,
        public ?int $valueOtifDenominatorMinor = null,
        public ?string $valueCurrency = null,
        public ?string $valueBasis = null,
    ) {}
}
