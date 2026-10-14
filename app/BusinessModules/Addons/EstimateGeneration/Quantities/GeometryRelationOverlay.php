<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationChoiceProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;

/** Resolves user choices in memory; persisted source entities remain immutable. */
final class GeometryRelationOverlay
{
    public static function authorizes(ProjectModelSnapshot $snapshot, array $decisions, Entity $child, Entity $parent, string $relation): bool
    {
        $definition = GeometryInventory::RELATIONS[$relation] ?? null;
        $review = $child->attributes['geometry_relation_reviews'][$relation] ?? [];
        if ($definition === null || ($review['status'] ?? null) !== 'confirmed' || $child->id === $parent->id
            || GeometryInventory::type($child) !== $definition[1] || GeometryInventory::type($parent) !== $definition[0]
            || ($child->attributes[$definition[2]] ?? null) !== $parent->id
            || [$child->organizationId, $child->projectId, $child->sessionId] !== [$parent->organizationId, $parent->projectId, $parent->sessionId]) {
            return false;
        }
        $facts = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->id === ($review['fact_id'] ?? null)));
        $selected = array_values(array_filter($decisions, static fn ($decision): bool => $decision->id === ($review['decision_id'] ?? null)));
        if (count($facts) !== 1 || count($selected) !== 1) {
            return false;
        }
        $fact = $facts[0];

        return $fact->entityId === $child->id && $fact->sourceVersion === $child->sourceVersion
            && $fact->type === 'geometry_parent_'.$relation && is_array($fact->value)
            && ($fact->value['relation'] ?? null) === $relation && ($fact->value['parent_entity_id'] ?? null) === $parent->id
            && ($fact->value['parent_source_version'] ?? null) === $parent->sourceVersion
            && (new EvaluationChoiceProof)->accepted($snapshot, $fact, $selected[0]);
    }

    public static function factIds(ProjectModelSnapshot $snapshot): array
    {
        return array_values(array_map(static fn ($fact): string => $fact->id,
            array_filter($snapshot->facts, static fn ($fact): bool => str_starts_with($fact->type, 'geometry_parent_'))));
    }

    public static function apply(ProjectModelSnapshot $snapshot, array $decisions, array $documentAnchors): ProjectModelSnapshot
    {
        $current = GeometryInventory::entities($snapshot, $documentAnchors);
        $byId = array_column($snapshot->entities, null, 'id');
        $choices = [];
        foreach ($snapshot->facts as $fact) {
            $relation = substr($fact->type, strlen('geometry_parent_'));
            if (str_starts_with($fact->type, 'geometry_parent_') && isset(GeometryInventory::RELATIONS[$relation])) {
                $choices[$fact->entityId][$relation][] = $fact;
            }
        }
        $decisionIndex = [];
        foreach ($decisions as $decision) {
            if ($decision->selectedFactId !== null) {
                $decisionIndex[$decision->selectedFactId][] = $decision;
            }
        }
        foreach ($choices as $childId => $relations) {
            $child = $byId[$childId] ?? null;
            if ($child === null) {
                continue;
            }
            $attributes = $child->attributes;
            foreach ($relations as $relation => $facts) {
                $definition = GeometryInventory::RELATIONS[$relation];
                if (GeometryInventory::type($child) !== $definition[1]) {
                    continue;
                }
                unset($attributes[$definition[2]]);
                $attributes['geometry_relation_reviews'][$relation] = ['status' => 'unresolved', 'fact_ids' => array_column($facts, 'id')];
                if (count($facts) !== 1) {
                    continue;
                }
                $fact = $facts[0];
                $value = $fact->value;
                $parent = is_array($value) ? ($current[$value['parent_entity_id'] ?? ''] ?? null) : null;
                $selected = $decisionIndex[$fact->id] ?? [];
                if (! isset($current[$childId]) || $fact->sourceVersion !== $child->sourceVersion
                    || $parent === null || $parent->id === $childId || GeometryInventory::type($parent) !== $definition[0]
                    || ($value['relation'] ?? null) !== $relation || ($value['parent_source_version'] ?? null) !== $parent->sourceVersion
                    || count($selected) !== 1 || ! (new EvaluationChoiceProof)->accepted($snapshot, $fact, $selected[0])) {
                    continue;
                }
                $attributes[$definition[2]] = $parent->id;
                $attributes['geometry_relation_reviews'][$relation] = ['status' => 'confirmed', 'fact_id' => $fact->id,
                    'decision_id' => $selected[0]->id, 'evidence_ids' => $fact->evidenceIds, 'parent_source_version' => $parent->sourceVersion];
            }
            $byId[$childId] = new Entity($child->id, $child->organizationId, $child->projectId, $child->sessionId,
                $child->sourceVersion, $child->type, $child->stableKey, $attributes);
        }

        return new ProjectModelSnapshot(array_values($byId), $snapshot->facts, $snapshot->evidence, $snapshot->conflicts);
    }
}
