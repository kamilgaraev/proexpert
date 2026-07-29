<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services;

use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO\InventoryBalanceFact;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO\InventoryDemandFact;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO\InventoryMovementFact;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO\InventoryReorderPolicy;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO\InventoryRiskMetric;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

final readonly class InventoryRiskFormula
{
    /**
     * @param  iterable<InventoryMovementFact>  $movementFacts
     */
    public function row(
        InventoryBalanceFact $opening,
        InventoryBalanceFact $closing,
        iterable $movementFacts,
        ?InventoryDemandFact $demand,
        ?InventoryReorderPolicy $policy,
    ): InventoryRiskMetric {
        $this->assertSameUnit($opening, $closing);

        $consumption = BigDecimal::zero();
        $movementDelta = BigDecimal::zero();
        foreach ($movementFacts as $movement) {
            if (
                $movement->unitDimension !== $closing->unitDimension
                || $movement->unitCode !== $closing->unitCode
                || $movement->conversionVersion !== $closing->conversionVersion
            ) {
                throw new DomainException('Inventory movement unit must match balance unit.');
            }
            $quantity = BigDecimal::of($movement->quantity);
            if ($quantity->isNegative() && $movement->type !== 'adjustment') {
                throw new DomainException('Inventory movement fact quantity must be non-negative.');
            }
            if ($movement->type === 'issue') {
                $consumption = $consumption->plus($quantity);
            }
            $movementDelta = match ($movement->type) {
                'receipt', 'transfer_in', 'return' => $movementDelta->plus($quantity),
                'issue', 'transfer_out' => $movementDelta->minus($quantity),
                'adjustment' => $movementDelta->plus($quantity),
                default => throw new DomainException('Unsupported inventory movement type.'),
            };
        }

        $openingOnHand = BigDecimal::of($opening->onHandQuantity);
        $closingOnHand = BigDecimal::of($closing->onHandQuantity);
        $reserved = BigDecimal::of($closing->reservedQuantity);
        if ($openingOnHand->isNegative() || $closingOnHand->isNegative() || $reserved->isNegative()) {
            throw new DomainException('Inventory balances cannot be negative.');
        }
        if (! $openingOnHand->plus($movementDelta)->isEqualTo($closingOnHand)) {
            throw new DomainException('Inventory movement replay does not reconcile with closing balance.');
        }
        $available = $closingOnHand->minus($reserved);
        if ($available->isNegative()) {
            throw new DomainException('Reserved inventory cannot exceed on-hand inventory.');
        }
        $averageOnHand = $openingOnHand
            ->plus($closingOnHand)
            ->dividedBy(BigDecimal::of(2), 6, RoundingMode::Unnecessary);
        $warnings = [];
        $value = null;
        if ($closing->unitPriceMinor === null || $closing->currency === null || $closing->currencySource === null) {
            $warnings[] = 'missing_valuation_basis';
        } else {
            $valueDecimal = $closingOnHand->multipliedBy($closing->unitPriceMinor)->strippedOfTrailingZeros();
            if ($valueDecimal->getScale() > 0) {
                throw new DomainException('Inventory value is not representable in minor currency units.');
            }
            $value = $valueDecimal->toInt();
        }

        $recommended = null;
        if ($demand !== null && $policy !== null) {
            $this->assertDemandUnit($closing, $demand);
            if ($available->isLessThanOrEqualTo($policy->reorderPointQuantity)) {
                $recommendedDecimal = BigDecimal::of($policy->targetQuantity)
                    ->minus($available)
                    ->plus($demand->approvedQuantity)
                    ->plus($policy->safetyStockQuantity);
                if ($recommendedDecimal->isNegative()) {
                    $recommendedDecimal = BigDecimal::zero();
                }
                $recommended = (string) $recommendedDecimal->toScale(3, RoundingMode::Unnecessary);
            }
        }

        return new InventoryRiskMetric(
            availableQuantity: (string) $available->toScale(3, RoundingMode::Unnecessary),
            consumptionQuantity: (string) $consumption->toScale(3, RoundingMode::Unnecessary),
            turnover: $averageOnHand->isZero()
                ? null
                : (string) $consumption->dividedBy($averageOnHand, 8, RoundingMode::HalfUp),
            onHandValueMinor: $value,
            currency: $value === null ? null : $closing->currency,
            recommendedOrderQuantity: $recommended,
            qualityWarnings: $warnings,
        );
    }

    private function assertSameUnit(InventoryBalanceFact $opening, InventoryBalanceFact $closing): void
    {
        if (
            $opening->unitDimension !== $closing->unitDimension
            || $opening->unitCode !== $closing->unitCode
            || $opening->conversionVersion !== $closing->conversionVersion
        ) {
            throw new DomainException('Opening and closing inventory units must match.');
        }
    }

    private function assertDemandUnit(InventoryBalanceFact $balance, InventoryDemandFact $demand): void
    {
        if (
            $balance->unitDimension !== $demand->unitDimension
            || $balance->unitCode !== $demand->unitCode
            || $balance->conversionVersion !== $demand->conversionVersion
        ) {
            throw new DomainException('Inventory demand unit must match balance unit.');
        }
    }
}
