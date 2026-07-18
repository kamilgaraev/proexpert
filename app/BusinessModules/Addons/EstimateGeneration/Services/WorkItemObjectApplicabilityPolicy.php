<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

final class WorkItemObjectApplicabilityPolicy
{
    private const NON_RESIDENTIAL_QUANTITY_KEYS = [
        'heating.air_curtains',
        'office.network_points',
        'office.partitions',
        'ventilation.office_points',
        'ventilation.warehouse_points',
        'warehouse.fire',
        'warehouse.floor_hardener',
        'warehouse.floor_joints',
        'warehouse.gates',
        'warehouse.loading_nodes',
    ];

    /**
     * @param  array<string, mixed>  $analysis
     */
    public static function allows(string $quantityKey, array $analysis): bool
    {
        if (! in_array($quantityKey, self::NON_RESIDENTIAL_QUANTITY_KEYS, true)) {
            return true;
        }

        $object = is_array($analysis['object'] ?? null) ? $analysis['object'] : [];

        foreach ([$object['object_type'] ?? null, $object['building_type'] ?? null] as $type) {
            if (is_string($type) && ObjectTypeSignalClassifier::isResidential($type)) {
                return false;
            }
        }

        return true;
    }
}
