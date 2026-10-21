<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationChoiceProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final class RoofSystemScope
{
    public const CONTRACT = 'roof-system:v1';

    public const SYSTEMS = ['concrete_tile', 'metal_tile', 'flexible_shingle', 'standing_seam'];

    public static function isRoofWork(array $item): bool
    {
        return ($item['metadata']['composition_work_key'] ?? $item['work_key'] ?? $item['metadata']['quantity_key'] ?? null) === 'roof.covering';
    }

    public static function roofs(ProjectModelSnapshot $snapshot, array $documentVersions): array
    {
        return array_filter(GeometryInventory::entities($snapshot, $documentVersions), static fn ($entity): bool => GeometryInventory::type($entity) === 'roof'
            && ! ($entity->attributes['contextual_only'] ?? false) && ! ($entity->attributes['excluded_by_document_note'] ?? false)
            && ! ($entity->attributes['properties']['contextual_only'] ?? false) && ! ($entity->attributes['properties']['excluded_by_document_note'] ?? false));
    }

    public static function permits(array $item, array $systems): bool
    {
        if (! self::isRoofWork($item)) {
            return true;
        }
        $entity = $item['metadata']['scope_entity_id'] ?? null;
        $system = is_string($entity) ? ($systems[$entity] ?? null) : null;

        return is_array($system) && is_array($item['metadata']['scope_entity_binding'] ?? null)
            && is_array($item['metadata']['roof_system'] ?? null)
            && CanonicalPipelineJson::encode($item['metadata']['roof_system']) === CanonicalPipelineJson::encode($system);
    }

    public static function bind(array $item, array $systems): array
    {
        if (! self::isRoofWork($item)) {
            return $item;
        }
        $entity = $item['metadata']['scope_entity_id'] ?? null;
        $system = is_string($entity) ? ($systems[$entity] ?? null) : null;
        unset($item['metadata']['roof_system']);
        if ($system !== null) {
            $item['metadata']['roof_system'] = $system;
        }

        return $item;
    }

    public static function select(ProjectModelSnapshot $snapshot, array $decisions, array $documentVersions): array
    {
        $roofs = self::roofs($snapshot, $documentVersions);
        $accepted = array_column(array_filter($decisions, static fn ($decision): bool => $decision instanceof Decision), null, 'id');
        $conflicted = [];
        foreach ($snapshot->conflicts as $conflict) {
            foreach ($conflict->status === 'unresolved' ? $conflict->facts : [] as $fact) {
                $conflicted[$fact->id][$fact->version][$fact->sourceVersion] = true;
            }
        }
        $choices = [];
        foreach ($snapshot->facts as $fact) {
            if ($fact->type === 'roof_system' && isset($roofs[$fact->entityId]) && $fact->sourceVersion === $roofs[$fact->entityId]->sourceVersion) {
                $choices[$fact->entityId][] = $fact;
            }
        }
        $result = [];
        $proofs = new EvaluationChoiceProof;
        foreach ($choices as $id => $facts) {
            if (count($facts) !== 1) {
                continue;
            }
            $roof = $roofs[$id];
            $fact = $facts[0];
            $value = $fact->value;
            $decision = is_array($value) ? ($accepted[$value['decision_id'] ?? ''] ?? null) : null;
            if (! is_array($value) || ($value['contract'] ?? null) !== self::CONTRACT || $fact->unit !== null
                || array_diff(array_keys($value), ['contract', 'system', 'entity_fingerprint', 'target_source_version', 'decision_id', 'reason']) !== []
                || [$fact->organizationId, $fact->projectId, $fact->sessionId] !== [$roof->organizationId, $roof->projectId, $roof->sessionId]
                || ! in_array($value['system'] ?? null, self::SYSTEMS, true)
                || ($value['entity_fingerprint'] ?? null) !== GeometryInventory::entityFingerprint($roof, $documentVersions)
                || ($value['target_source_version'] ?? null) !== $fact->sourceVersion
                || ! is_string($value['reason'] ?? null) || mb_strlen(trim($value['reason'])) < 10 || strlen($value['reason']) > 1000
                || isset($conflicted[$fact->id][$fact->version][$fact->sourceVersion])
                || ! $decision instanceof Decision || (int) $decision->actorId < 1 || $decision->version !== $fact->version
                || ! $proofs->accepted($snapshot, $fact, $decision)) {
                continue;
            }
            $result[$id] = [...$value, 'roof_entity_id' => $id, 'fact_id' => $fact->id,
                'fact_version' => $fact->version, 'evidence_ids' => $fact->evidenceIds];
        }
        ksort($result, SORT_STRING);

        return $result;
    }
}
