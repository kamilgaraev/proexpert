<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class InventoryMovementFact
{
    private const TYPES = ['receipt', 'issue', 'transfer_in', 'transfer_out', 'return', 'adjustment'];

    public function __construct(
        public string $type,
        public string $quantity,
        public string $unitDimension,
        public string $unitCode,
        public string $conversionVersion,
        public ?string $transferPairKey = null,
        public ?int $unitCostMinor = null,
        public ?string $currency = null,
        public ?string $costBasis = null,
        public ?DateTimeImmutable $occurredAt = null,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported inventory movement type.');
        }
        if (($unitCostMinor === null) !== ($currency === null)
            || ($unitCostMinor === null) !== ($costBasis === null)
            || ($unitCostMinor !== null && $unitCostMinor < 0)) {
            throw new InvalidArgumentException('Inventory movement valuation basis is incomplete.');
        }
    }
}
