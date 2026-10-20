<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\NormativeUnitData;
use Brick\Math\BigDecimal;

final class NormativeUnitNormalizer
{
    private const UNIT_RULES = [
        ['length', 'м', 1000.0, ['км', 'km', 'километр', 'километра', 'километры'], []],
        ['length', 'м', 0.001, ['мм', 'mm'], []],
        ['length', 'м', 0.01, ['см', 'cm'], []],
        ['length', 'м', 1.0, ['м', 'мп', 'пм', 'погм', 'метр', 'метра', 'метры', 'm', 'lm', 'rm', 'linm', 'linearmeter'], []],
        ['volume', 'м3', 1.0, [], ['м3', 'm3', 'cbm', 'кубм']],
        ['area', 'м2', 1.0, [], ['м2', 'm2', 'sqm', 'квм']],
        ['labor_time', 'чел-ч', 1.0, [], ['чел-ч', 'чел/ч', 'челч']],
        ['machine_time', 'маш-ч', 1.0, [], ['маш-ч', 'маш/ч', 'машч']],
        ['piece', 'шт', 1.0, ['pcs', 'piece', 'pieces', 'item', 'count', 'точка', 'точки', 'точек', 'точ', 'шт', 'ед'], ['компл', 'точк', 'штук', 'единиц']],
        ['time', 'ч', 1.0, ['h', 'hour', 'hours', 'ч', 'час', 'часа', 'часов'], []],
        ['mass', 'кг', 1.0, ['kg', 'кг', 'килограмм', 'килограмма', 'килограммы'], []],
        ['power', 'кВт', 1.0, ['kw', 'квт', 'киловатт', 'киловатта', 'киловатты'], []],
        ['mass', 'кг', 1000.0, ['т', 't'], ['тонн']],
        ['time', 'мес', 1.0, ['мес', 'месяц', 'месяца', 'месяцев'], []],
    ];

    /** @return array<string, string> */
    public static function normalizationReplacements(): array
    {
        return ["\u{00A0}" => ' ', '³' => '3', '²' => '2', '^3' => '3', '^2' => '2',
            'куб. м' => 'м3', 'куб.м' => 'м3', 'куб м' => 'м3', 'кв. м' => 'м2', 'кв.м' => 'м2',
            'кв м' => 'м2', 'пог. м' => 'м', 'пог.м' => 'м'];
    }

    public static function compatibleSearchPattern(string $workUnit): ?string
    {
        $work = self::parseDetailed($workUnit);
        if (! $work->isKnown()) {
            return null;
        }
        $exact = [];
        $prefixes = [];
        foreach (self::UNIT_RULES as [$dimension, $base, , $aliases, $starts]) {
            if ($dimension === $work->dimension && $base === $work->baseUnit) {
                array_push($exact, ...$aliases);
                array_push($prefixes, ...$starts);
            }
        }
        $patterns = [];
        foreach ([[$exact, '$'], [$prefixes, '']] as [$aliases, $end]) {
            if ($aliases !== []) {
                $patterns[] = '^('.implode('|', array_map(static fn (string $alias): string => preg_quote($alias), $aliases)).')'.$end;
            }
        }

        return implode('|', $patterns);
    }

    /**
     * @return array{base: string, multiplier: float}
     */
    public static function parse(string $unit): array
    {
        $parsed = self::parseDetailed($unit);

        return [
            'base' => $parsed->baseUnit,
            'multiplier' => $parsed->multiplier,
        ];
    }

    public static function parseDetailed(string $unit): NormativeUnitData
    {
        $raw = trim($unit);
        $normalized = self::normalize($raw);
        $multiplier = 1.0;

        if (preg_match('/^тыс\.?\s+(.+)$/u', $normalized, $matches) === 1) {
            $multiplier = 1000.0;
            $normalized = trim($matches[1]);
        }

        if (preg_match('/^(\d+(?:[,.]\d+)?)\s*(.+)$/u', $normalized, $matches) === 1) {
            $parsedMultiplier = (float) str_replace(',', '.', $matches[1]);
            $multiplier *= $parsedMultiplier > 0 ? $parsedMultiplier : 1.0;
            $normalized = trim($matches[2]);
        }

        [$dimension, $baseUnit, $unitMultiplier] = self::classify($normalized);

        return new NormativeUnitData(
            raw: $raw,
            normalized: self::compact($normalized),
            dimension: $dimension,
            baseUnit: $baseUnit,
            multiplier: $multiplier * $unitMultiplier,
        );
    }

    public static function compatible(string $left, string $right): bool
    {
        $left = self::parseDetailed($left);
        $right = self::parseDetailed($right);

        return $left->compatibleWith($right);
    }

    public static function quantityFactor(string $workUnit, string $normUnit): float
    {
        return self::safeQuantityFactor($workUnit, $normUnit) ?? 1.0;
    }

    public static function safeQuantityFactor(string $workUnit, string $normUnit): ?float
    {
        $work = self::parse($workUnit);
        $norm = self::parse($normUnit);

        if ($work['base'] === '' || $work['base'] !== $norm['base'] || $norm['multiplier'] <= 0) {
            return null;
        }

        return round($work['multiplier'] / $norm['multiplier'], 10);
    }

    public static function safeQuantityFactorDecimal(string $workUnit, string $normUnit): ?string
    {
        $work = self::parseExact($workUnit);
        $norm = self::parseExact($normUnit);
        if ($work['base'] === '' || $work['base'] !== $norm['base']
            || BigDecimal::of($norm['multiplier'])->isLessThanOrEqualTo(0)) {
            return null;
        }

        try {
            return BigDecimal::of($work['multiplier'])
                ->dividedByExact($norm['multiplier'])
                ->strippedOfTrailingZeros()
                ->__toString();
        } catch (\Brick\Math\Exception\MathException) {
            return null;
        }
    }

    /** @return array{base: string, multiplier: string} */
    private static function parseExact(string $unit): array
    {
        $normalized = self::normalize(trim($unit));
        $multiplier = BigDecimal::one();
        if (preg_match('/^тыс\.?\s+(.+)$/u', $normalized, $matches) === 1) {
            $multiplier = $multiplier->multipliedBy('1000');
            $normalized = trim($matches[1]);
        }
        if (preg_match('/^(\d+(?:[,.]\d+)?)\s*(.+)$/u', $normalized, $matches) === 1) {
            $prefix = BigDecimal::of(str_replace(',', '.', $matches[1]));
            if ($prefix->isGreaterThan(0)) {
                $multiplier = $multiplier->multipliedBy($prefix);
            }
            $normalized = trim($matches[2]);
        }
        [, $base, $unitMultiplier] = self::classify($normalized);
        $multiplier = $multiplier->multipliedBy($unitMultiplier === 1000.0 ? '1000' : '1');

        return [
            'base' => $base,
            'multiplier' => $multiplier->strippedOfTrailingZeros()->__toString(),
        ];
    }

    /**
     * @return array{string, string, float}
     */
    private static function classify(string $unit): array
    {
        $unit = self::compact($unit);

        foreach (self::UNIT_RULES as [$dimension, $base, $multiplier, $aliases, $prefixes]) {
            if (in_array($unit, $aliases, true)) {
                return [$dimension, $base, $multiplier];
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with($unit, $prefix)) {
                    return [$dimension, $base, $multiplier];
                }
            }
        }

        return ['', '', 1.0];
    }

    private static function normalize(string $unit): string
    {
        $unit = mb_strtolower(trim($unit));
        $replacements = self::normalizationReplacements();

        return str_replace(array_keys($replacements), array_values($replacements), $unit);
    }

    private static function compact(string $unit): string
    {
        return str_replace([' ', '.', ','], '', self::normalize($unit));
    }
}
