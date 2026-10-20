<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;

final class OpeningGeometryCoverage
{
    public static function current(ProjectModelSnapshot $snapshot, array $decisions, Entity $opening, ?array $documentVersions): ?array
    {
        $entities = GeometryInventory::entities($snapshot, $documentVersions);
        $wall = $entities[$opening->attributes['wall_id'] ?? ''] ?? null;
        if (! isset($entities[$opening->id]) || GeometryInventory::type($opening) !== 'opening'
            || $wall === null || GeometryInventory::type($wall) !== 'wall'
            || [$opening->organizationId, $opening->projectId, $opening->sessionId] !== [$wall->organizationId, $wall->projectId, $wall->sessionId]) {
            return null;
        }
        $facts = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->entityId === $wall->id
            && $fact->sourceVersion === $wall->sourceVersion && is_array($fact->value)
            && in_array($fact->type, ['geometry_coverage', 'geometry_coverage_wall_openings'], true)
            && ($fact->value['relation'] ?? null) === 'wall_openings'));
        if (count($facts) !== 1) {
            return null;
        }
        $fact = $facts[0];
        if ([$fact->organizationId, $fact->projectId, $fact->sessionId] !== [$wall->organizationId, $wall->projectId, $wall->sessionId]) {
            return null;
        }
        foreach ($snapshot->conflicts as $conflict) {
            foreach ($conflict->status === 'unresolved' ? $conflict->facts : [] as $candidate) {
                if ($candidate->id === $fact->id && $candidate->version === $fact->version && $candidate->sourceVersion === $fact->sourceVersion) {
                    return null;
                }
            }
        }
        $coverage = GeometryCoverage::fromFact($fact, 'wall_openings');
        $inventory = GeometryInventory::capture($snapshot, $wall->id, 'wall_openings', $documentVersions);
        if ($coverage === null || ! GeometryCoverage::isUserConfirmed($fact, $snapshot, $decisions)
            || ($coverage->representation['inventory_contract'] ?? null) !== 'geometry-inventory:v1'
            || ($coverage->representation['inventory_fingerprint'] ?? null) !== $inventory['fingerprint']
            || $coverage->issue($fact->status, count($inventory['member_ids'])) !== null
            || ! in_array($opening->id, $inventory['member_ids'], true) || $inventory['unassigned_ids'] !== []) {
            return null;
        }

        return [...$coverage->identity(), 'member_ids' => $inventory['member_ids']];
    }
}
