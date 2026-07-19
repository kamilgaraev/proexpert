<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

final class DirectTakeoffRequiredWorkItems
{
    private const KEYS = [
        'earth.export',
        'electrical.main_cable',
        'electrical.power_lines',
        'electrical.trays',
        'electrical.grounding',
        'heating.air_curtains',
        'heating.pipe',
        'rough.floor',
        'lighting.lines',
        'networks.external',
        'office.network_points',
        'openings.doors',
        'openings.windows',
        'plumbing.pipe',
        'roof.gutter',
        'roof.area',
        'roof.flat_area',
        'roof.rafters',
        'site.setup',
        'site.geodesy',
        'stairs.flights',
        'stairs.landings',
        'stairs.railings',
        'foundation.prep',
        'heating.radiators',
        'heating.unit',
        'sanitary.tile',
        'sanitary.waterproofing',
        'sanitary.points',
        'sewerage.pipe',
        'sewerage.outlets',
        'sewerage.risers',
        'sewerage.revisions',
        'ventilation.air_exchange',
        'ventilation.office_points',
        'ventilation.warehouse_points',
        'walls.lintels',
        'warehouse.fire',
        'warehouse.lighting',
        'warehouse.low_current',
    ];

    public static function contains(string $quantityKey): bool
    {
        return in_array($quantityKey, self::KEYS, true);
    }
}
