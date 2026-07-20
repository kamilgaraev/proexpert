<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final class ResidentialAbstractResourceConversionCatalog
{
    private const CONVERSIONS = [
        '07-01-021-01|05.1.03.09' => [
            'from_unit' => 'м3',
            'to_unit' => 'шт',
            'factor' => '0.04',
            'assumption' => 'precast_lintel_volume_per_piece_m3:0.04',
            'name_markers' => ['перемыч'],
        ],
        '12-01-013-07|12.2.05.02' => [
            'from_unit' => 'м3',
            'to_unit' => 'м2',
            'factor' => '0.20',
            'assumption' => 'mineral_wool_thickness_m:0.20',
            'name_markers' => ['минерал', 'ват'],
        ],
        '15-01-019-05|06.2.05.04' => [
            'from_unit' => 'т',
            'to_unit' => 'м2',
            'factor' => '0.02',
            'assumption' => 'ceramic_tile_mass_t_per_m2:0.02',
            'name_markers' => ['плит', 'керамич'],
        ],
    ];

    public function find(string $normCode, string $groupCode): ?array
    {
        return self::CONVERSIONS[$normCode.'|'.$groupCode] ?? null;
    }

    public function supportedGroupCodes(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $key): string => explode('|', $key, 2)[1],
            array_keys(self::CONVERSIONS),
        )));
    }
}
