<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

final class QuantityFormulaCatalog
{
    public const VERSION = '1.0.0';

    /** @var array<string, array{unit: string, formula: string}> */
    private const FORMULAS = [
        'floor_area' => ['unit' => 'm2', 'formula' => 'floor.area.sum'],
        'ceiling_area' => ['unit' => 'm2', 'formula' => 'ceiling.area.sum'],
        'opening_area' => ['unit' => 'm2', 'formula' => 'opening.area.sum'],
        'gross_wall_area' => ['unit' => 'm2', 'formula' => 'wall.gross_area.sum'],
        'net_wall_area' => ['unit' => 'm2', 'formula' => 'wall.net_area.sum'],
        'foundation_volume' => ['unit' => 'm3', 'formula' => 'foundation.volume.sum'],
        'roof_area' => ['unit' => 'm2', 'formula' => 'roof.area.sum'],
    ];

    /** @return array{unit: string, formula: string} */
    public function definition(string $key): array
    {
        if (str_starts_with($key, 'engineering.')) {
            return ['unit' => '', 'formula' => 'engineering.measurement.sum'];
        }

        if (! isset(self::FORMULAS[$key])) {
            throw new \InvalidArgumentException('Unknown quantity formula: '.$key);
        }

        return self::FORMULAS[$key];
    }
}
