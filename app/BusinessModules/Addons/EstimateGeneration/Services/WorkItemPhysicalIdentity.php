<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

final class WorkItemPhysicalIdentity
{
    public const MAX_GROUP_ITEMS = 100;

    /** @param array<string, mixed> $item
     * @return array{string, string, int|string|null, int|string|null}|null
     */
    public static function fromWorkItem(array $item): ?array
    {
        $metadata = $item['metadata'] ?? null;
        if (! is_array($metadata) || ! is_string($metadata['scope_entity_id'] ?? null) || trim($metadata['scope_entity_id']) === '') {
            return null;
        }
        $workKeys = [];
        foreach (['material_scenario_work_key', 'composition_work_key'] as $field) {
            $value = $metadata[$field] ?? null;
            if ($value === null) {
                continue;
            }
            if (! is_string($value) || trim($value) === '') {
                return null;
            }
            $workKeys[] = trim($value);
        }
        if ($workKeys === [] || count(array_unique($workKeys, SORT_STRING)) !== 1) {
            return null;
        }
        foreach (['technology_package_id', 'technology_formula_id'] as $field) {
            if (isset($metadata[$field]) && ! is_string($metadata[$field]) && ! is_int($metadata[$field])) {
                return null;
            }
        }

        return [trim($metadata['scope_entity_id']), $workKeys[0], $metadata['technology_package_id'] ?? null, $metadata['technology_formula_id'] ?? null];
    }

    /** @param list<array<string, mixed>> $items */
    public static function canResolveGroup(array $items): bool
    {
        if (count($items) < 2 || count($items) > self::MAX_GROUP_ITEMS) {
            return false;
        }
        $expected = null;
        $keys = [];
        foreach ($items as $item) {
            $identity = self::fromWorkItem($item);
            $key = $item['key'] ?? null;
            if (! is_string($key) || $key === '' || isset($keys[$key]) || $identity === null) {
                return false;
            }
            $keys[$key] = true;
            if ($expected !== null && $expected !== $identity) {
                return false;
            }
            $expected = $identity;
        }

        return true;
    }
}
