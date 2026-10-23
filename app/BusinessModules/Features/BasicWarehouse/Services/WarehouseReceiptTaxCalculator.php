<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\DTOs\WarehouseReceiptTaxSnapshot;
use App\BusinessModules\Features\BasicWarehouse\Enums\WarehouseReceiptVatMode;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class WarehouseReceiptTaxCalculator
{
    public function calculate(
        string $quantity,
        string $unitPrice,
        WarehouseReceiptVatMode $mode,
        ?string $rate = null,
    ): WarehouseReceiptTaxSnapshot {
        $quantityValue = $this->decimal($quantity, 3, '999999999999.999', true);
        $priceValue = $this->decimal($unitPrice, 2, '9999999999999.99');
        $lineAmount = $quantityValue->multipliedBy($priceValue)->toScale(2, RoundingMode::HalfUp);

        if ($mode === WarehouseReceiptVatMode::None) {
            if ($rate !== null) {
                throw new InvalidArgumentException('A non-taxable receipt cannot have a VAT rate.');
            }

            return new WarehouseReceiptTaxSnapshot(
                $mode,
                (string) $quantityValue,
                (string) $priceValue,
                null,
                (string) $lineAmount,
                '0.00',
                (string) $lineAmount,
            );
        }

        if ($rate === null) {
            throw new InvalidArgumentException('A taxable receipt requires an explicit VAT rate.');
        }
        $rateValue = $this->decimal($rate, 2, '100.00');
        if ($mode === WarehouseReceiptVatMode::Included) {
            $gross = $lineAmount;
            $vat = $gross->multipliedBy($rateValue)->dividedBy($rateValue->plus('100'), 2, RoundingMode::HalfUp);
            $net = $gross->minus($vat);
        } else {
            $net = $lineAmount;
            $vat = $net->multipliedBy($rateValue)->dividedBy('100', 2, RoundingMode::HalfUp);
            $gross = $net->plus($vat);
        }

        return new WarehouseReceiptTaxSnapshot(
            $mode,
            (string) $quantityValue,
            (string) $priceValue,
            (string) $rateValue,
            (string) $net,
            (string) $vat,
            (string) $gross,
        );
    }

    private function decimal(string $value, int $scale, string $maximum, bool $positive = false): BigDecimal
    {
        if (! preg_match('/\A\d+(?:\.\d+)?\z/', $value)) {
            throw new InvalidArgumentException('Expected an unsigned decimal string.');
        }
        try {
            $number = BigDecimal::of($value)->toScale($scale, RoundingMode::Unnecessary);
        } catch (MathException $exception) {
            throw new InvalidArgumentException('The decimal precision exceeds the storage scale.', 0, $exception);
        }
        if ($number->compareTo($maximum) > 0 || ($positive && $number->compareTo('0') <= 0)) {
            throw new InvalidArgumentException('The decimal value is outside the storage range.');
        }

        return $number;
    }
}
