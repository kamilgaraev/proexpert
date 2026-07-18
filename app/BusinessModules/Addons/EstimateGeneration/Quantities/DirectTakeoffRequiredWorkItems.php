<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

final class DirectTakeoffRequiredWorkItems
{
    private const KEYS = [
        'site.setup',
        'site.geodesy',
        'foundation.prep',
        'heating.radiators',
        'heating.unit',
        'sanitary.tile',
        'sewerage.outlets',
        'sewerage.risers',
        'sewerage.revisions',
        'ventilation.air_exchange',
        'walls.lintels',
    ];

    public static function contains(string $quantityKey): bool
    {
        return in_array($quantityKey, self::KEYS, true);
    }
}
