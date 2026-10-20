<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final class WallEstimateScope
{
    public const CONTRACT = 'wall-assembly:v1';

    public const SURFACES = ['external' => ['inside', 'outside'], 'partition' => ['side_a', 'side_b']];

    public static function validSelection(mixed $role, mixed $surfaces): bool
    {
        return is_string($role) && isset(self::SURFACES[$role]) && is_array($surfaces) && array_is_list($surfaces)
            && count($surfaces) <= 2 && count(array_filter($surfaces, 'is_string')) === count($surfaces)
            && count(array_unique($surfaces)) === count($surfaces) && array_diff($surfaces, self::SURFACES[$role]) === [];
    }

    public static function walls(ProjectModelSnapshot $snapshot, array $documentVersions): array
    {
        return array_filter(GeometryInventory::entities($snapshot, $documentVersions), static fn ($entity): bool => GeometryInventory::type($entity) === 'wall'
            && ! ($entity->attributes['contextual_only'] ?? false) && ! ($entity->attributes['excluded_by_document_note'] ?? false)
            && ! ($entity->attributes['properties']['contextual_only'] ?? false) && ! ($entity->attributes['properties']['excluded_by_document_note'] ?? false));
    }

    public static function select(ProjectModelSnapshot $snapshot, array $decisions, array $documentVersions): array
    {
        if (GeometryRelationOverlay::factIds($snapshot) !== []) {
            $snapshot = GeometryRelationOverlay::apply($snapshot, $decisions, $documentVersions);
        }
        $walls = self::walls($snapshot, $documentVersions);
        $byWall = [];
        foreach ($snapshot->facts as $fact) {
            if ($fact->type === 'wall_assembly' && isset($walls[$fact->entityId]) && $fact->sourceVersion === $walls[$fact->entityId]->sourceVersion) {
                $byWall[$fact->entityId][] = $fact;
            }
        }
        $conflicted = [];
        foreach ($snapshot->conflicts as $conflict) {
            if ($conflict->status === 'unresolved') {
                foreach ($conflict->facts as $fact) {
                    if ($fact->type === 'wall_assembly' && isset($walls[$fact->entityId]) && $fact->sourceVersion === $walls[$fact->entityId]->sourceVersion
                        && in_array($fact->id, array_column($byWall[$fact->entityId] ?? [], 'id'), true)) {
                        $conflicted[$fact->entityId] = true;
                    }
                }
            }
        }
        $proofs = array_column($snapshot->evidence, null, 'id');
        $accepted = array_column(array_filter($decisions, static fn ($decision): bool => $decision instanceof Decision), null, 'id');
        $result = [];
        foreach ($walls as $id => $wall) {
            $facts = $byWall[$id] ?? [];
            $fact = count($facts) === 1 ? $facts[0] : null;
            if (! $fact instanceof Fact || isset($conflicted[$id]) || $fact->status !== 'confirmed' || $fact->origin !== 'user_assumption'
                || $fact->unit !== null || ! is_array($fact->value) || $fact->sourceVersion !== $wall->sourceVersion
                || [$fact->organizationId, $fact->projectId, $fact->sessionId] !== [$wall->organizationId, $wall->projectId, $wall->sessionId]) {
                continue;
            }
            $value = $fact->value;
            $decision = $accepted[$value['decision_id'] ?? ''] ?? null;
            if (($value['contract'] ?? null) !== self::CONTRACT || ! self::validSelection($value['role'] ?? null, $value['finished_surfaces'] ?? null)
                || ($value['entity_fingerprint'] ?? null) !== GeometryInventory::entityFingerprint($wall, $documentVersions)
                || ! is_string($value['reason'] ?? null) || trim($value['reason']) === ''
                || ! $decision instanceof Decision || $decision->selectedFactId !== $fact->id || $decision->targetType !== 'fact'
                || $decision->targetId !== ($fact->supersedesFactId ?? $fact->id) || $decision->actorType !== 'user'
                || $decision->version !== $fact->version
                || [$decision->organizationId, $decision->projectId, $decision->sessionId, $decision->sourceVersion]
                    !== [$fact->organizationId, $fact->projectId, $fact->sessionId, $fact->sourceVersion]) {
                continue;
            }
            $hash = hash('sha256', CanonicalPipelineJson::encode($value));
            $valid = $fact->evidenceIds !== [];
            foreach ($fact->evidenceIds as $evidenceId) {
                $proof = $proofs[$evidenceId] ?? null;
                $valid = $valid && $proof !== null && $proof->sourceType === 'user_input'
                    && $proof->producerName === EvidenceProducer::UserInputNormalizer->value && $proof->choiceHash === $hash
                    && in_array($evidenceId, $decision->evidenceIds, true)
                    && [$proof->organizationId, $proof->projectId, $proof->sessionId, $proof->sourceVersion]
                        === [$fact->organizationId, $fact->projectId, $fact->sessionId, $fact->sourceVersion];
            }
            if ($valid) {
                $result[$id] = [...$value, 'fact_id' => $fact->id, 'fact_version' => $fact->version, 'evidence_ids' => $fact->evidenceIds];
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }
}
