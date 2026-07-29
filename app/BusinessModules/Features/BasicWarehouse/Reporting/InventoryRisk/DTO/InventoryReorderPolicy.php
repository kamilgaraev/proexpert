<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

final readonly class InventoryReorderPolicy
{
    public function __construct(
        public string $minimumQuantity,
        public string $reorderPointQuantity,
        public string $targetQuantity,
        public string $safetyStockQuantity,
        public int $leadTimeDays,
        public string $sourceVersion,
    ) {
        $minimum = BigDecimal::of($minimumQuantity);
        $reorderPoint = BigDecimal::of($reorderPointQuantity);
        $target = BigDecimal::of($targetQuantity);
        $safetyStock = BigDecimal::of($safetyStockQuantity);
        if ($minimum->isNegative()
            || $reorderPoint->isLessThan($minimum)
            || $target->isLessThan($reorderPoint)
            || $safetyStock->isNegative()
            || $leadTimeDays < 0
            || trim($sourceVersion) === '') {
            throw new InvalidArgumentException('Inventory reorder policy is invalid.');
        }
    }
}
