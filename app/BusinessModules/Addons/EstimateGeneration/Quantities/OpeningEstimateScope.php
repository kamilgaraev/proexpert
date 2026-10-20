<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationChoiceProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;

final class OpeningEstimateScope
{
    public const CONTRACT = 'opening-scope:v1';

    public const KINDS = ['door', 'window'];

    public static function openings(ProjectModelSnapshot $snapshot, array $documentVersions): array
    {
        $entities = GeometryInventory::entities($snapshot, $documentVersions);
        $walls = WallEstimateScope::walls($snapshot, $documentVersions);

        return array_filter($entities, static fn ($entity): bool => GeometryInventory::type($entity) === 'opening'
            && isset($walls[$entity->attributes['wall_id'] ?? ''])
            && [$entity->organizationId, $entity->projectId, $entity->sessionId] === [
                $walls[$entity->attributes['wall_id']]->organizationId, $walls[$entity->attributes['wall_id']]->projectId, $walls[$entity->attributes['wall_id']]->sessionId]
            && ! ($entity->attributes['contextual_only'] ?? false) && ! ($entity->attributes['excluded_by_document_note'] ?? false)
            && ! ($entity->attributes['properties']['contextual_only'] ?? false) && ! ($entity->attributes['properties']['excluded_by_document_note'] ?? false));
    }

    public static function select(ProjectModelSnapshot $snapshot, array $decisions, array $documentVersions): array
    {
        $openings = self::openings($snapshot, $documentVersions);
        $accepted = array_column(array_filter($decisions, static fn ($decision): bool => $decision instanceof Decision), null, 'id');
        $conflicted = [];
        foreach ($snapshot->conflicts as $conflict) {
            if ($conflict->status === 'unresolved') {
                foreach ($conflict->facts as $fact) {
                    $conflicted[$fact->id][$fact->version][$fact->sourceVersion] = true;
                }
            }
        }
        $choices = [];
        foreach ($snapshot->facts as $fact) {
            $id = $fact->entityId;
            if ($fact->type !== 'opening_scope' || ! is_array($fact->value) || ! isset($openings[$id])) {
                continue;
            }
            $choices[$id][] = $fact;
        }
        $result = [];
        $proofs = new EvaluationChoiceProof;
        foreach ($choices as $id => $facts) {
            if (count($facts) !== 1) {
                continue;
            }
            $fact = $facts[0];
            $value = $fact->value;
            $opening = $openings[$id];
            $decision = $accepted[$value['decision_id'] ?? ''] ?? null;
            if (($value['contract'] ?? null) !== self::CONTRACT || ! in_array($value['opening_kind'] ?? null, self::KINDS, true)
                || $fact->unit !== null || $fact->sourceVersion !== $opening->sourceVersion
                || ($value['target_source_version'] ?? null) !== $opening->sourceVersion
                || ($value['entity_fingerprint'] ?? null) !== GeometryInventory::entityFingerprint($opening, $documentVersions)
                || [$fact->organizationId, $fact->projectId, $fact->sessionId] !== [$opening->organizationId, $opening->projectId, $opening->sessionId]
                || isset($conflicted[$fact->id][$fact->version][$fact->sourceVersion])
                || ! $decision instanceof Decision || (int) $decision->actorId < 1 || $decision->version !== $fact->version || ! $proofs->accepted($snapshot, $fact, $decision)) {
                continue;
            }
            $result[$id] = ['contract' => self::CONTRACT, 'opening_kind' => $value['opening_kind'],
                'opening_entity_id' => $id, 'entity_fingerprint' => $value['entity_fingerprint'],
                'target_source_version' => $opening->sourceVersion, 'fact_id' => $fact->id,
                'fact_version' => $fact->version, 'decision_id' => $decision->id, 'evidence_ids' => $fact->evidenceIds];
        }
        ksort($result, SORT_STRING);

        return $result;
    }
}
