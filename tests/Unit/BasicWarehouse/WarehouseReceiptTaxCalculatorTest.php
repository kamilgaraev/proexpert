<?php

declare(strict_types=1);

namespace Tests\Unit\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Enums\WarehouseReceiptVatMode;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseReceiptTaxCalculator;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WarehouseReceiptTaxCalculatorTest extends TestCase
{
    public static function amountCases(): array
    {
        return [
            'without VAT' => ['1.25', '100.00', 'none', null, '125.00', '0.00', '125.00'],
            'VAT on top' => ['1.25', '100.00', 'on_top', '20', '125.00', '25.00', '150.00'],
            'VAT included' => ['1.25', '120.00', 'included', '20', '125.00', '25.00', '150.00'],
            'fractional quantity with included VAT' => ['2.345', '12.34', 'included', '20', '24.12', '4.82', '28.94'],
            'fractional quantity with VAT on top' => ['2.345', '12.34', 'on_top', '20', '28.94', '5.79', '34.73'],
            'kopeck remainder' => ['1.000', '1000.00', 'included', '20', '833.33', '166.67', '1000.00'],
            'included VAT half kopeck' => ['1.000', '0.03', 'included', '20', '0.02', '0.01', '0.03'],
            'large included VAT half kopeck' => ['1.000', '9999999999999.99', 'included', '20', '8333333333333.32', '1666666666666.67', '9999999999999.99'],
            'half kopeck' => ['1.005', '1.00', 'on_top', '20', '1.01', '0.20', '1.21'],
            'explicit zero rate' => ['2.345', '12.34', 'included', '0', '28.94', '0.00', '28.94'],
            'zero price' => ['1.000', '0.00', 'on_top', '20', '0.00', '0.00', '0.00'],
            'large exact decimal' => ['1.000', '9999999999999.99', 'none', null, '9999999999999.99', '0.00', '9999999999999.99'],
        ];
    }

    #[DataProvider('amountCases')]
    public function test_calculates_exact_line_amounts_without_losing_a_kopeck(
        string $quantity, string $price, string $mode, ?string $rate,
        string $net, string $vat, string $gross,
    ): void {
        $snapshot = (new WarehouseReceiptTaxCalculator)->calculate($quantity, $price, WarehouseReceiptVatMode::from($mode), $rate);

        self::assertSame($net, $snapshot->netAmount);
        self::assertSame($vat, $snapshot->vatAmount);
        self::assertSame($gross, $snapshot->grossAmount);
        self::assertSame($gross, (string) BigDecimal::of($snapshot->netAmount)->plus($snapshot->vatAmount));
        self::assertSame($mode, $snapshot->mode->value);
        self::assertSame($mode === 'none' ? null : ($rate === '0' ? '0.00' : '20.00'), $snapshot->rate);
        self::assertSame(1, $snapshot->toArray()['version']);
        self::assertSame($snapshot->unitPrice, $snapshot->toArray()['unit_price']);
    }

    public static function invalidCases(): array
    {
        return [
            'zero quantity' => ['0', '1.00', 'none', null],
            'negative quantity' => ['-1', '1.00', 'none', null],
            'quantity precision' => ['1.0001', '1.00', 'none', null],
            'price precision' => ['1', '1.001', 'none', null],
            'price outside storage range' => ['1', '10000000000000.00', 'none', null],
            'quantity outside storage range' => ['1000000000000.000', '1', 'none', null],
            'missing rate' => ['1', '1', 'included', null],
            'negative rate' => ['1', '1', 'on_top', '-1'],
            'excessive rate' => ['1', '1', 'included', '100.01'],
            'rate precision' => ['1', '1', 'on_top', '20.001'],
            'rate with non-taxable mode' => ['1', '1', 'none', '0'],
        ];
    }

    #[DataProvider('invalidCases')]
    public function test_rejects_an_inconsistent_or_unstorable_tax_context(string $quantity, string $price, string $mode, ?string $rate): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new WarehouseReceiptTaxCalculator)->calculate($quantity, $price, WarehouseReceiptVatMode::from($mode), $rate);
    }
}
