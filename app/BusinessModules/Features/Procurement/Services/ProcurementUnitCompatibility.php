<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

final class ProcurementUnitCompatibility
{
    public static function matches(string $orderUnit, ?string $materialUnitName, ?string $materialUnitShortName): bool
    {
        $order = self::normalize($orderUnit);
        if ($order === '') {
            return false;
        }

        return in_array($order, array_filter([
            self::normalize((string) $materialUnitName),
            self::normalize((string) $materialUnitShortName),
        ]), true);
    }

    private static function normalize(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }
}
