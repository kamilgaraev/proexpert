<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\NormativeUnitData;

final class NormativeUnitNormalizer
{
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

    /**
     * @return array{string, string, float}
     */
    private static function classify(string $unit): array
    {
        $unit = self::compact($unit);

        return match (true) {
            in_array($unit, ['км', 'километр', 'километра', 'километры'], true) => ['length', 'м', 1000.0],
            in_array($unit, ['м', 'мп', 'пм', 'погм', 'метр', 'метра', 'метры'], true) => ['length', 'м', 1.0],
            str_starts_with($unit, 'м3'),
            str_starts_with($unit, 'кубм') => ['volume', 'м3', 1.0],
            str_starts_with($unit, 'м2'),
            str_starts_with($unit, 'квм') => ['area', 'м2', 1.0],
            str_starts_with($unit, 'чел-ч'),
            str_starts_with($unit, 'чел/ч'),
            str_starts_with($unit, 'челч') => ['labor_time', 'чел-ч', 1.0],
            str_starts_with($unit, 'маш-ч'),
            str_starts_with($unit, 'маш/ч'),
            str_starts_with($unit, 'машч') => ['machine_time', 'маш-ч', 1.0],
            str_starts_with($unit, 'компл') => ['set', 'компл', 1.0],
            $unit === 'шт',
            str_starts_with($unit, 'штук') => ['piece', 'шт', 1.0],
            $unit === 'ед',
            str_starts_with($unit, 'единиц') => ['piece', 'ед', 1.0],
            in_array($unit, ['кг', 'килограмм', 'килограмма', 'килограммы'], true) => ['mass', 'кг', 1.0],
            $unit === 'т',
            str_starts_with($unit, 'тонн') => ['mass', 'кг', 1000.0],
            in_array($unit, ['мес', 'месяц', 'месяца', 'месяцев'], true) => ['time', 'мес', 1.0],
            default => ['', '', 1.0],
        };
    }

    private static function normalize(string $unit): string
    {
        $unit = mb_strtolower(trim($unit));
        $unit = str_replace(["\u{00A0}", '³', '²', '^3', '^2'], [' ', '3', '2', '3', '2'], $unit);

        return str_replace(
            ['куб. м', 'куб.м', 'куб м', 'кв. м', 'кв.м', 'кв м'],
            ['м3', 'м3', 'м3', 'м2', 'м2', 'м2'],
            $unit
        );
    }

    private static function compact(string $unit): string
    {
        return str_replace([' ', '.', ','], '', self::normalize($unit));
    }
}
