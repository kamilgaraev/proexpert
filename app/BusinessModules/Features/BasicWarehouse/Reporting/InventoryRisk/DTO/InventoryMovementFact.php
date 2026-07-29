<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO;

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
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported inventory movement type.');
        }
    }
}
