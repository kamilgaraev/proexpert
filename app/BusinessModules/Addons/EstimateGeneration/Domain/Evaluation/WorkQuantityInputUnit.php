<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use Brick\Math\BigDecimal;

/** Convert an explicitly entered work measure to a physical base fact without losing its multiplier. */
final class WorkQuantityInputUnit
{
    /** @return array{unit: string, factor: string}|null */
    public static function parse(string $source): ?array
    {
        if (strlen($source) > 30) {
            return null;
        }
        foreach (['length' => 'm', 'area' => 'm2', 'volume' => 'm3', 'count' => 'count', 'mass' => 'kg', 'time' => 'h'] as $group => $unit) {
            $factor = FactVocabulary::factor($group, $source);
            if ($factor !== null) {
                return ['unit' => $unit, 'factor' => $factor];
            }
        }
        $normalized = FactVocabulary::unit($source) ?? '';
        if (preg_match('/\A(?:тыс\.?\s*)?([0-9]+(?:[,.][0-9]+)?)/u', $normalized, $match) === 1
            && ! BigDecimal::of(str_replace(',', '.', $match[1]))->isGreaterThan(0)) {
            return null;
        }
        foreach (['m', 'm2', 'm3', 'count', 'kg', 'h'] as $unit) {
            $factor = NormativeUnitNormalizer::safeQuantityFactorDecimal($source, $unit);
            if ($factor !== null && BigDecimal::of($factor)->isGreaterThan(0)) {
                return ['unit' => $unit, 'factor' => $factor];
            }
        }

        return null;
    }

    /** @return array{value: string, unit: string}|null */
    public static function normalize(string $value, string $source): ?array
    {
        $parsed = self::parse($source);
        if ($parsed === null) {
            return null;
        }

        return ['value' => (string) BigDecimal::of($value)->multipliedBy($parsed['factor'])->strippedOfTrailingZeros(), 'unit' => $parsed['unit']];
    }
}
