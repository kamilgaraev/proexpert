<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

final class WorkItemObjectApplicabilityPolicy
{
    /**
     * @param  array<string, mixed>  $analysis
     */
    public static function allows(string $quantityKey, array $analysis): bool
    {
        $allowedObjectTypes = self::allowedObjectTypes($quantityKey);
        if ($allowedObjectTypes === null) {
            return true;
        }

        $objectType = self::canonicalObjectType($analysis);

        return $objectType !== null && in_array($objectType, $allowedObjectTypes, true);
    }

    /** @return list<string>|null */
    private static function allowedObjectTypes(string $quantityKey): ?array
    {
        if (str_starts_with($quantityKey, 'office.') || $quantityKey === 'ventilation.office_points') {
            return ['office', 'mixed_warehouse_office'];
        }
        if (str_starts_with($quantityKey, 'warehouse.') || $quantityKey === 'ventilation.warehouse_points') {
            return ['warehouse', 'mixed_warehouse_office'];
        }
        if ($quantityKey === 'heating.air_curtains') {
            return ['office', 'warehouse', 'mixed_warehouse_office'];
        }

        return null;
    }

    /** @param array<string, mixed> $analysis */
    private static function canonicalObjectType(array $analysis): ?string
    {
        $object = is_array($analysis['object'] ?? null) ? $analysis['object'] : [];
        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $factsSummary = is_array($documentContext['facts_summary'] ?? null) ? $documentContext['facts_summary'] : [];

        foreach ([
            $object['object_type'] ?? null,
            $object['building_type'] ?? null,
            $factsSummary['object_type'] ?? null,
            $factsSummary['building_type'] ?? null,
            $object['description'] ?? null,
            $object['title'] ?? null,
            $object['name'] ?? null,
        ] as $type) {
            if (! is_string($type) || trim($type) === '' || in_array(mb_strtolower(trim($type)), ['custom', 'building'], true)) {
                continue;
            }

            $canonical = ObjectTypeSignalClassifier::canonical($type);
            if (in_array($canonical, ['residential', 'office', 'warehouse', 'mixed_warehouse_office'], true)) {
                return $canonical;
            }
        }

        return null;
    }
}
