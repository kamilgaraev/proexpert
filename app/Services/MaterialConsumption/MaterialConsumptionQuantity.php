<?php

declare(strict_types=1);

namespace App\Services\MaterialConsumption;

use App\Exceptions\BusinessLogicException;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

use function trans_message;

final class MaterialConsumptionQuantity
{
    public const PATTERN = '/^[0-9]{1,18}(?:\.[0-9]{1,6})?$/D';

    public static function of(mixed $value, string $errorKey = 'material_consumption.fact_quantity_invalid'): BigDecimal
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            throw new BusinessLogicException(trans_message($errorKey), 422);
        }
        try {
            $decimal = BigDecimal::of($value);
        } catch (MathException) {
            throw new BusinessLogicException(trans_message($errorKey), 422);
        }
        if ($decimal->isNegative()) {
            throw new BusinessLogicException(trans_message($errorKey), 422);
        }

        return $decimal;
    }

    public static function format(BigDecimal $value): string
    {
        return (string) $value->toScale(6, RoundingMode::HalfUp)->stripTrailingZeros();
    }

    public static function scale(BigDecimal $value): BigDecimal
    {
        return $value->toScale(6, RoundingMode::HalfUp);
    }
}
