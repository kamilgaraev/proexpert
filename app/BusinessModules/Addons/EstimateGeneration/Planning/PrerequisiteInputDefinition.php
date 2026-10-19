<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use Brick\Math\BigDecimal;

final class PrerequisiteInputDefinition
{
    private const UNITS = [
        'site_area' => 'm2',
        'foundation_area' => 'm2',
        'pile_count' => 'item',
        'foundation_column_count' => 'item',
        'waterproofing_area' => 'm2',
        'facade_area' => 'm2',
        'roof_area' => 'm2',
        'waste_volume' => 'm3',
        'engineering_system_count' => 'item',
        'disturbed_area' => 'm2',
    ];

    public static function contract(string $input, string $unit): ?array
    {
        if ((self::UNITS[$input] ?? null) !== $unit) {
            return null;
        }

        return ['type' => $unit === 'item' ? 'positive_integer' : 'positive_decimal',
            'unit' => $unit, 'basis' => ['input', 'measurement', 'assumption']];
    }

    public static function accepts(string $input, mixed $value, ?string $unit, string $resultUnit): bool
    {
        if (self::contract($input, $resultUnit) === null
            || (! is_int($value) && ! is_string($value) && ! is_float($value))
            || preg_match('/\A[0-9]{1,15}(?:\.[0-9]{1,8})?\z/D', (string) $value) !== 1
            || ! in_array($unit, $resultUnit === 'item' ? ['item', 'count', 'pcs'] : [$resultUnit], true)) {
            return false;
        }
        $number = BigDecimal::of((string) $value);

        return $number->isGreaterThan(0)
            && ($resultUnit !== 'item' || $number->strippedOfTrailingZeros()->getScale() <= 0);
    }
}
