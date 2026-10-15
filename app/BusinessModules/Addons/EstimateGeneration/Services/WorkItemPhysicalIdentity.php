<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

final class WorkItemPhysicalIdentity
{
    public const MAX_GROUP_ITEMS = 100;

    /** @param list<array<string, mixed>> $items */
    public static function canResolveGroup(array $items): bool
    {
        if ($items === [] || count($items) > self::MAX_GROUP_ITEMS) {
            return false;
        }
        $expected = null;
        $keys = [];
        foreach ($items as $item) {
            $metadata = $item['metadata'] ?? [];
            $entity = is_array($metadata) ? ($metadata['scope_entity_id'] ?? null) : null;
            $work = is_array($metadata) ? ($metadata['material_scenario_work_key'] ?? $metadata['composition_work_key'] ?? null) : null;
            $key = $item['key'] ?? null;
            if (! is_string($key) || $key === '' || isset($keys[$key])
                || ! is_string($entity) || trim($entity) === '' || ! is_string($work) || trim($work) === '') {
                return false;
            }
            $keys[$key] = true;
            foreach (['technology_package_id', 'technology_formula_id'] as $field) {
                if (isset($metadata[$field]) && (! is_string($metadata[$field]) && ! is_int($metadata[$field]))) {
                    return false;
                }
            }
            $identity = [trim($entity), trim($work), $metadata['technology_package_id'] ?? null, $metadata['technology_formula_id'] ?? null];
            if ($expected !== null && $expected !== $identity) {
                return false;
            }
            $expected = $identity;
        }

        return true;
    }
}
