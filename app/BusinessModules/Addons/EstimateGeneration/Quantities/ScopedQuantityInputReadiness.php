<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use Brick\Math\BigDecimal;

final class ScopedQuantityInputReadiness
{
    public const CONTRACT = 'scoped-quantity-input-readiness:v1';

    public static function permitsPartialPlanning(array $warning, ProjectModelSnapshot $snapshot, string $token,
        array $sections, array $quantities, array $documentVersions, array $decisions): bool
    {
        $definition = match ($warning['formula'] ?? null) {
            'wall_net_area' => ['wall', 'm2'], 'equipment_count' => ['equipment', 'count'], default => null,
        };
        if (($warning['code'] ?? null) !== 'canonical_quantity_aggregate_unresolved' || $definition === null
            || array_intersect($sections, FactVocabulary::entitySections($definition[0])) === []) {
            return false;
        }
        $keys = $warning['quantity_keys'] ?? null;
        $ids = $warning['quantity_ids'] ?? null;
        if (! is_array($keys) || ! array_is_list($keys) || ! is_array($ids) || ! array_is_list($ids)
            || count($keys) < 2 || count($keys) > 100 || count($ids) !== count($keys)
            || ($warning['total_quantities'] ?? null) !== count($keys) || ($warning['truncated'] ?? null) !== false
            || count(array_filter($keys, static fn ($key): bool => is_string($key) && $key !== '')) !== count($keys)
            || count(array_filter($ids, static fn ($id): bool => is_string($id) && $id !== '')) !== count($ids)
            || count(array_unique($keys)) !== count($keys) || count(array_unique($ids)) !== count($ids)) {
            return false;
        }
        $required = $definition[0] === 'equipment' ? EquipmentEstimateScope::select($snapshot, $decisions)
            : array_filter(GeometryInventory::entities($snapshot, $documentVersions),
                static fn ($entity): bool => GeometryInventory::type($entity) === 'wall'
                    && ! ($entity->attributes['contextual_only'] ?? false) && ! ($entity->attributes['excluded_by_document_note'] ?? false)
                    && ! ($entity->attributes['properties']['contextual_only'] ?? false) && ! ($entity->attributes['properties']['excluded_by_document_note'] ?? false));
        if (count($required) !== count($keys)) {
            return false;
        }
        $seen = $matched = [];
        foreach ($quantities as $key => $quantity) {
            if (! $quantity instanceof QuantityData || $quantity->formulaKey !== $warning['formula']) {
                continue;
            }
            $entity = $quantity->formulaInputs['entity_id'] ?? null;
            if (! is_string($key) || ! in_array($key, $keys, true) || $quantity->key !== $key
                || ! is_string($entity) || ! isset($required[$entity]) || isset($seen[$entity])
                || $quantity->modelVersion !== $token || $quantity->unit !== $definition[1]
                || ($quantity->formulaInputs['snapshot_identity']['input_fingerprint'] ?? null) !== $token
                || $quantity->reviewBlockers !== [] || $quantity->evidenceIds === []
                || ($quantity->source === QuantitySource::Estimated && $quantity->assumptions === [])
                || ! preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $quantity->amount)
                || BigDecimal::of($quantity->amount)->isLessThanOrEqualTo(0)) {
                return false;
            }
            $seen[$entity] = true;
            $matched[$key] = true;
        }

        return count($matched) === count($keys) && count($seen) === count($required);
    }
}
