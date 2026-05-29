<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

final class NormativeUnitNormalizer
{
    /**
     * @return array{base: string, multiplier: float}
     */
    public static function parse(string $unit): array
    {
        $unit = mb_strtolower(trim($unit));
        $unit = str_replace(["\u{00A0}", '³', '²', '^3', '^2'], [' ', '3', '2', '3', '2'], $unit);

        $multiplier = 1.0;
        if (preg_match('/^(\d+(?:[,.]\d+)?)\s*(.+)$/u', $unit, $matches) === 1) {
            $parsedMultiplier = (float) str_replace(',', '.', $matches[1]);
            $multiplier = $parsedMultiplier > 0 ? $parsedMultiplier : 1.0;
            $unit = trim($matches[2]);
        } elseif (preg_match('/^тыс\.?\s+(.+)$/u', $unit, $matches) === 1) {
            $multiplier = 1000.0;
            $unit = trim($matches[1]);
        }

        return [
            'base' => self::baseUnit($unit),
            'multiplier' => $multiplier,
        ];
    }

    public static function compatible(string $left, string $right): bool
    {
        $left = self::parse($left);
        $right = self::parse($right);

        return $left['base'] !== '' && $left['base'] === $right['base'];
    }

    public static function quantityFactor(string $workUnit, string $normUnit): float
    {
        $work = self::parse($workUnit);
        $norm = self::parse($normUnit);

        if ($work['base'] === '' || $work['base'] !== $norm['base'] || $norm['multiplier'] <= 0) {
            return 1.0;
        }

        return $work['multiplier'] / $norm['multiplier'];
    }

    private static function baseUnit(string $unit): string
    {
        $unit = str_replace(
            ['куб. м', 'куб.м', 'куб м', 'кв. м', 'кв.м', 'кв м'],
            ['м3', 'м3', 'м3', 'м2', 'м2', 'м2'],
            $unit
        );
        $unit = str_replace([' ', '.', ','], '', $unit);

        return match (true) {
            str_starts_with($unit, 'м3'),
            str_starts_with($unit, 'м³'),
            str_starts_with($unit, 'кубм') => 'м3',
            str_starts_with($unit, 'м2'),
            str_starts_with($unit, 'м²'),
            str_starts_with($unit, 'квм') => 'м2',
            str_starts_with($unit, 'чел-ч'),
            str_starts_with($unit, 'чел/ч'),
            str_starts_with($unit, 'челч') => 'чел-ч',
            str_starts_with($unit, 'маш-ч'),
            str_starts_with($unit, 'маш/ч'),
            str_starts_with($unit, 'машч') => 'маш-ч',
            str_starts_with($unit, 'компл') => 'компл',
            $unit === 'шт',
            str_starts_with($unit, 'штук') => 'шт',
            $unit === 'кг',
            str_starts_with($unit, 'килограмм') => 'кг',
            $unit === 'т',
            str_starts_with($unit, 'тонн') => 'т',
            in_array($unit, ['м', 'мп', 'пм', 'погм', 'метр', 'метра', 'метры'], true) => 'м',
            $unit === 'ед',
            str_starts_with($unit, 'единиц') => 'ед',
            default => $unit,
        };
    }
}
