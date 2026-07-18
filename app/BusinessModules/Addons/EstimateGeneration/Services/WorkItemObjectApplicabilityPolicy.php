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
        'warehouse.panel_flashings',
        'warehouse.wall_panels',
    ];

    /**
     * @param  array<string, mixed>  $analysis
     */
    public static function allows(string $quantityKey, array $analysis): bool
    {
        if (! str_starts_with($quantityKey, 'warehouse.')
            && ! str_starts_with($quantityKey, 'office.')
            && ! in_array($quantityKey, self::NON_RESIDENTIAL_QUANTITY_KEYS, true)
        ) {
            return true;
        }

        $object = is_array($analysis['object'] ?? null) ? $analysis['object'] : [];
        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $factsSummary = is_array($documentContext['facts_summary'] ?? null) ? $documentContext['facts_summary'] : [];

        foreach ([
            $object['object_type'] ?? null,
            $object['building_type'] ?? null,
            $object['description'] ?? null,
            $object['title'] ?? null,
            $object['name'] ?? null,
            $factsSummary['object_type'] ?? null,
            $factsSummary['building_type'] ?? null,
        ] as $type) {
            if (is_string($type) && ObjectTypeSignalClassifier::isResidential($type)) {
                return false;
            }
        }

        return true;
    }
}
