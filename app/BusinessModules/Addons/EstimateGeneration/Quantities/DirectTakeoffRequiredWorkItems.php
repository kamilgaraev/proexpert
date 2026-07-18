<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

final class DirectTakeoffRequiredWorkItems
{
    private const KEYS = [
        'site.setup',
        'site.geodesy',
        'facade.area',
        'foundation.prep',
        'heating.radiators',
        'heating.unit',
        'openings.doors',
        'openings.windows',
        'sanitary.tile',
        'stairs.flights',
        'stairs.landings',
        'stairs.railings',
        'ventilation.air_exchange',
        'walls.lintels',
    ];

    public static function contains(string $quantityKey): bool
    {
        return in_array($quantityKey, self::KEYS, true);
    }
}
