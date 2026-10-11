<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

use Brick\Math\BigDecimal;

final class FactVocabulary
{
    public const VERSION = 'project-facts:v1';

    private const PARAMETERS = [
        'room' => ['area', 'length', 'width', 'height'],
        'wall' => ['length', 'height', 'thickness'],
        'opening' => ['width', 'height', 'area'],
        'site' => ['area', 'depth'],
        'roof' => ['plan_area', 'slope_rise', 'slope_run'],
        'roof_facet' => ['plan_area', 'slope_rise', 'slope_run'],
        'roof_opening' => ['area'],
    ];

    private const ALIASES = [
        'area' => ['area', 'room_area', 'floor_area', 'opening_area', 'площадь', 'площадь пола', 'площадь помещения', 'площадь проёма', 'площадь проема'],
        'length' => ['length', 'room_length', 'wall_length', 'длина', 'длина стены'],
        'width' => ['width', 'room_width', 'opening_width', 'ширина', 'ширина проёма', 'ширина проема'],
        'height' => ['height', 'room_height', 'wall_height', 'opening_height', 'высота', 'высота стены', 'высота помещения', 'высота проёма', 'высота проема'],
        'thickness' => ['thickness', 'wall_thickness', 'толщина', 'толщина стены'],
        'depth' => ['depth', 'excavation_depth', 'глубина', 'глубина выемки'],
        'plan_area' => ['plan_area', 'roof_plan_area', 'площадь кровли в плане'],
        'slope_rise' => ['slope_rise', 'roof_slope_rise', 'подъём ската', 'подъем ската'],
        'slope_run' => ['slope_run', 'roof_slope_run', 'заложение ската'],
    ];

    private const METRIC_UNITS = [
        'mm' => ['m', '0.001'], 'cm' => ['m', '0.01'], 'm' => ['m', '1'], 'in' => ['m', '0.0254'], 'ft' => ['m', '0.3048'],
        'mm2' => ['m2', '0.000001'], 'cm2' => ['m2', '0.0001'], 'm2' => ['m2', '1'],
        'mm3' => ['m3', '0.000000001'], 'cm3' => ['m3', '0.000001'], 'm3' => ['m3', '1'],
        'count' => ['count', '1'], 'kg' => ['kg', '1'], 't' => ['kg', '1000'], 'h' => ['h', '1'],
    ];

    public static function entityType(string $entityKey): ?string
    {
        foreach (array_keys(self::PARAMETERS) as $type) {
            if (preg_match('/^'.preg_quote($type, '/').'[:._-]/D', mb_strtolower(trim($entityKey))) === 1) {
                if ($type === 'roof' && preg_match('/^roof_(facet|opening)[:._-]/D', mb_strtolower(trim($entityKey))) === 1) {
                    continue;
                }

                return $type;
            }
        }

        return null;
    }

    public static function parameter(string $type): string
    {
        $type = mb_strtolower(trim(preg_replace('/([a-z])([A-Z])/', '$1_$2', $type) ?? $type));
        foreach (self::ALIASES as $canonical => $aliases) {
            if (in_array($type, $aliases, true)) {
                return $canonical;
            }
        }

        return $type;
    }

    public static function aliases(string $parameter): array
    {
        return self::ALIASES[self::parameter($parameter)] ?? [$parameter];
    }

    public static function supports(string $entityType, string $parameter): bool
    {
        return in_array(self::parameter($parameter), self::PARAMETERS[$entityType] ?? [], true);
    }

    public static function unit(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }
        $normalized = str_replace(['²', '³', '^', ' '], ['2', '3', '', ''], mb_strtolower(trim($unit)));

        return match ($normalized) {
            'мм', 'mm' => 'mm', 'см', 'cm' => 'cm', 'м', 'm' => 'm',
            'мм2', 'mm2' => 'mm2', 'см2', 'cm2' => 'cm2', 'м2', 'm2' => 'm2',
            'мм3', 'mm3' => 'mm3', 'см3', 'cm3' => 'cm3', 'м3', 'm3' => 'm3',
            'шт', 'шт.', 'pcs', 'count' => 'count',
            'кг', 'kg' => 'kg', 'т', 't' => 't', 'ч', 'час', 'h' => 'h',
            'in', 'inch', 'inches', 'дюйм', 'дюймы' => 'in',
            'ft', 'foot', 'feet', 'фут', 'футы' => 'ft',
            default => $normalized,
        };
    }

    public static function numericUnit(?string $unit): bool
    {
        return isset(self::METRIC_UNITS[self::unit($unit) ?? '']);
    }

    public static function factor(string $group, ?string $unit): ?string
    {
        [$base, $factor] = self::METRIC_UNITS[self::unit($unit) ?? ''] ?? [null, null];

        return $base === match ($group) {
            'length' => 'm', 'area' => 'm2', 'volume' => 'm3', 'count' => 'count',
            'mass' => 'kg', 'time' => 'h', default => null,
        } ? $factor : null;
    }

    public static function measurementSignature(string $value, ?string $unit): string
    {
        $unit = self::unit($unit);
        [$canonicalUnit, $factor] = self::METRIC_UNITS[$unit ?? ''] ?? [$unit ?? '', '1'];

        $decimal = (string) BigDecimal::of($value)->multipliedBy($factor);

        return $canonicalUnit.':'.(str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal);
    }
}
