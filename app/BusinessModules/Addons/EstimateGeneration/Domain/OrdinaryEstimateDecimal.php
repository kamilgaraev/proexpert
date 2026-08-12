<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class OrdinaryEstimateDecimal
{
    private const QUANTITY_LIMIT = '1000000000000';

    private const UNIT_PRICE_LIMIT = '10000000000000000';

    private const MONEY_LIMIT = '10000000000000';

    private const RESOURCE_QUANTITY_LIMIT = '100000000000';

    public static function fitsQuantity(mixed $value): bool
    {
        return self::fits($value, self::QUANTITY_LIMIT);
    }

    public static function fitsUnitPrice(mixed $value): bool
    {
        return self::fits($value, self::UNIT_PRICE_LIMIT);
    }

    public static function fitsMoney(mixed $value): bool
    {
        return self::fits($value, self::MONEY_LIMIT);
    }

    public static function fitsResourceQuantity(mixed $value): bool
    {
        return self::fits($value, self::RESOURCE_QUANTITY_LIMIT);
    }

    public static function quantity(mixed $value): string
    {
        return self::storage($value, self::QUANTITY_LIMIT, 8, 'ordinary_estimate_quantity_out_of_range');
    }

    public static function unitPrice(mixed $value): string
    {
        return self::storage($value, self::UNIT_PRICE_LIMIT, 4, 'ordinary_estimate_unit_price_out_of_range');
    }

    public static function money(mixed $value): string
    {
        return self::storage($value, self::MONEY_LIMIT, 2, 'ordinary_estimate_money_out_of_range');
    }

    public static function resourceQuantity(mixed $value): string
    {
        return self::storage($value, self::RESOURCE_QUANTITY_LIMIT, 4, 'ordinary_estimate_resource_quantity_out_of_range');
    }

    public static function resourceUnitPrice(mixed $value): string
    {
        return self::storage($value, self::MONEY_LIMIT, 2, 'ordinary_estimate_resource_unit_price_out_of_range');
    }

    private static function fits(mixed $value, string $limit): bool
    {
        try {
            $decimal = BigDecimal::of((string) $value);

            return ! $decimal->isLessThan(0) && $decimal->isLessThan(BigDecimal::of($limit));
        } catch (\Throwable) {
            return false;
        }
    }

    private static function storage(mixed $value, string $limit, int $scale, string $error): string
    {
        if (! self::fits($value, $limit)) {
            throw new InvalidArgumentException($error);
        }

        return (string) BigDecimal::of((string) $value)->toScale($scale, RoundingMode::HalfUp);
    }
}
