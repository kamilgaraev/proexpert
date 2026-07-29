<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO\InventoryBalanceFact;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO\InventoryDemandFact;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO\InventoryMovementFact;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO\InventoryReorderPolicy;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services\InventoryRiskFormula;
use DomainException;
use PHPUnit\Framework\TestCase;

final class InventoryRiskFormulaTest extends TestCase
{
    public function test_balance_turnover_and_value_use_typed_quantity_and_price(): void
    {
        $metric = (new InventoryRiskFormula)->row(
            $this->balance('10.000', '2.000'),
            $this->balance('14.000', '3.000'),
            [
                $this->movement('receipt', '15.000'),
                $this->movement('issue', '6.000'),
                $this->movement('transfer_out', '5.000', 'T-1'),
            ],
            new InventoryDemandFact('4.000', 30, 'count', 'piece', 'unit-v1'),
            new InventoryReorderPolicy('0.000', '12.000', '20.000', '2.000', 7, 'policy-v1'),
        );

        self::assertSame('11.000', $metric->availableQuantity);
        self::assertSame('0.50000000', $metric->turnover);
        self::assertSame(7_000, $metric->onHandValueMinor);
        self::assertSame('6.000', $metric->consumptionQuantity);
    }

    public function test_zero_average_on_hand_has_null_turnover(): void
    {
        $metric = (new InventoryRiskFormula)->row(
            $this->balance('0.000', '0.000', priceMinor: null, currency: null),
            $this->balance('0.000', '0.000', priceMinor: null, currency: null),
            [],
            null,
            null,
        );

        self::assertNull($metric->turnover);
        self::assertNull($metric->onHandValueMinor);
        self::assertSame(['missing_valuation_basis'], $metric->qualityWarnings);
    }

    public function test_movement_replay_must_reconcile_with_closing_balance(): void
    {
        $this->expectException(DomainException::class);

        (new InventoryRiskFormula)->row(
            $this->balance('10.000', '0.000'),
            $this->balance('14.000', '0.000'),
            [$this->movement('receipt', '3.000')],
            null,
            null,
        );
    }

    public function test_reserved_quantity_cannot_exceed_on_hand(): void
    {
        $this->expectException(DomainException::class);

        (new InventoryRiskFormula)->row(
            $this->balance('10.000', '0.000'),
            $this->balance('10.000', '11.000'),
            [],
            null,
            null,
        );
    }

    public function test_negative_adjustment_reconciles_without_becoming_consumption(): void
    {
        $metric = (new InventoryRiskFormula)->row(
            $this->balance('10.000', '0.000'),
            $this->balance('8.000', '0.000'),
            [$this->movement('adjustment', '-2.000')],
            null,
            null,
        );

        self::assertSame('0.000', $metric->consumptionQuantity);
        self::assertSame('8.000', $metric->availableQuantity);
    }

    private function balance(
        string $onHand,
        string $reserved,
        ?int $priceMinor = 500,
        ?string $currency = 'RUB',
    ): InventoryBalanceFact {
        return new InventoryBalanceFact(
            onHandQuantity: $onHand,
            reservedQuantity: $reserved,
            unitDimension: 'count',
            unitCode: 'piece',
            conversionVersion: 'unit-v1',
            unitPriceMinor: $priceMinor,
            currency: $currency,
            currencySource: $currency === null ? null : 'purchase_receipt',
        );
    }

    private function movement(
        string $type,
        string $quantity,
        ?string $pair = null,
    ): InventoryMovementFact {
        return new InventoryMovementFact(
            type: $type,
            quantity: $quantity,
            unitDimension: 'count',
            unitCode: 'piece',
            conversionVersion: 'unit-v1',
            transferPairKey: $pair,
        );
    }
}
