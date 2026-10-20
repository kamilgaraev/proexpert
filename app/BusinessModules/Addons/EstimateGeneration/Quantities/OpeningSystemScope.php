<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationChoiceProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final class OpeningSystemScope
{
    public const CONTRACT = 'opening-system:v1';

    public const SYSTEMS = ['steel_glazed_window' => 'window', 'pvc_glazed_window' => 'window',
        'timber_hollow_core_door' => 'door', 'meranti_external_door' => 'door'];

    public static function choices(string $kind): array
    {
        return array_keys(array_filter(self::SYSTEMS, static fn (string $value): bool => $value === $kind));
    }

    public static function permits(array $item, array $systems): bool
    {
        if (! OpeningWorkItemExpander::isOpeningWork($item)) {
            return true;
        }
        $entity = $item['metadata']['scope_entity_id'] ?? $item['entity_id'] ?? null;
        $system = is_string($entity) ? ($systems[$entity] ?? null) : null;

        return is_array($system)
            && CanonicalPipelineJson::encode($item['metadata']['opening_system'] ?? null) === CanonicalPipelineJson::encode($system);
    }

    public static function bind(array $item, array $systems): array
    {
        if (! OpeningWorkItemExpander::isOpeningWork($item)) {
            return $item;
        }
        $entity = $item['metadata']['scope_entity_id'] ?? $item['entity_id'] ?? null;
        $system = is_string($entity) ? ($systems[$entity] ?? null) : null;
        if ($system !== null) {
            $item['metadata']['opening_system'] = $system;
        }

        return $item;
    }

    public static function select(ProjectModelSnapshot $snapshot, array $decisions, array $documentVersions): array
    {
        $scopes = OpeningEstimateScope::select($snapshot, $decisions, $documentVersions);
        $openings = OpeningEstimateScope::openings($snapshot, $documentVersions);
        $accepted = array_column(array_filter($decisions, static fn ($decision): bool => $decision instanceof Decision), null, 'id');
        $conflicted = [];
        foreach ($snapshot->conflicts as $conflict) {
            foreach ($conflict->status === 'unresolved' ? $conflict->facts : [] as $fact) {
                $conflicted[$fact->id][$fact->version][$fact->sourceVersion] = true;
            }
        }
        $choices = [];
        foreach ($snapshot->facts as $fact) {
            if ($fact->type === 'opening_system' && is_array($fact->value) && isset($scopes[$fact->entityId])) {
                $choices[$fact->entityId][] = $fact;
            }
        }
        $result = [];
        $proofs = new EvaluationChoiceProof;
        foreach ($choices as $id => $facts) {
            if (count($facts) !== 1) {
                continue;
            }
            $scope = $scopes[$id];
            $opening = $openings[$id];
            $fact = $facts[0];
            $value = $fact->value;
            $decision = $accepted[$value['decision_id'] ?? ''] ?? null;
            if (($value['contract'] ?? null) !== self::CONTRACT || $fact->unit !== null
                || [$fact->organizationId, $fact->projectId, $fact->sessionId] !== [$opening->organizationId, $opening->projectId, $opening->sessionId]
                || ! in_array($value['system'] ?? null, self::choices($scope['opening_kind']), true)
                || ($value['opening_kind'] ?? null) !== $scope['opening_kind']
                || ($value['scope_fact_id'] ?? null) !== $scope['fact_id'] || ($value['scope_fact_version'] ?? null) !== $scope['fact_version']
                || ($value['entity_fingerprint'] ?? null) !== $scope['entity_fingerprint']
                || $fact->sourceVersion !== $scope['target_source_version'] || ($value['target_source_version'] ?? null) !== $fact->sourceVersion
                || isset($conflicted[$fact->id][$fact->version][$fact->sourceVersion])
                || ! $decision instanceof Decision || (int) $decision->actorId < 1 || $decision->version !== $fact->version
                || ! $proofs->accepted($snapshot, $fact, $decision)) {
                continue;
            }
            $result[$id] = ['contract' => self::CONTRACT, 'system' => $value['system'], 'opening_kind' => $scope['opening_kind'],
                'opening_entity_id' => $id, 'scope_fact_id' => $scope['fact_id'], 'scope_fact_version' => $scope['fact_version'],
                'entity_fingerprint' => $scope['entity_fingerprint'], 'target_source_version' => $fact->sourceVersion,
                'fact_id' => $fact->id, 'fact_version' => $fact->version, 'decision_id' => $decision->id, 'evidence_ids' => $fact->evidenceIds];
        }
        ksort($result, SORT_STRING);

        return $result;
    }
}
