<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final class DocumentRequirementAcceptance
{
    public const BINDING_VERSION = 'document-applicability:v1';

    public static function valueHash(Fact $fact): string
    {
        return hash('sha256', CanonicalPipelineJson::encode(['type' => $fact->type, 'value' => $fact->value, 'unit' => $fact->unit]));
    }

    public function accepted(ProjectModelSnapshot $snapshot, array $decisions, Fact $documentFact, string $technologyKey, ?string $targetEntityId = null, ?string $section = null): bool
    {
        if ($documentFact->origin !== 'document' || $documentFact->status !== 'confirmed' || $documentFact->evidenceIds === []) {
            return false;
        }
        $current = array_column($snapshot->facts, null, 'id')[$documentFact->id] ?? null;
        if ($current === null || $current->version !== $documentFact->version || $current->sourceVersion !== $documentFact->sourceVersion
            || ! hash_equals(self::valueHash($current), self::valueHash($documentFact))) {
            return false;
        }
        $evidence = array_column($snapshot->evidence, null, 'id');
        $targetEntityId ??= $documentFact->entityId;
        $entities = array_column($snapshot->entities, null, 'id');
        $crossEntity = $targetEntityId !== $documentFact->entityId;
        $decisions = array_column(array_filter($decisions, static fn ($decision): bool => $decision instanceof Decision), null, 'selectedFactId');
        foreach ($snapshot->facts as $approval) {
            $value = $approval->value;
            if ($approval->entityId !== $targetEntityId || $approval->type !== 'evaluation_requirement.'.$documentFact->type
                || [$approval->organizationId, $approval->projectId, $approval->sessionId] !== [$documentFact->organizationId, $documentFact->projectId, $documentFact->sessionId]
                || $approval->origin !== 'user_input' || $approval->status !== 'confirmed' || ! is_array($value)
                || ($value['disposition'] ?? null) !== 'document_verified' || ($value['scope_version'] ?? null) !== ScopeCompletenessEvaluator::VERSION
                || ($value['technology_key'] ?? null) !== $technologyKey || ($value['document_fact_id'] ?? null) !== $documentFact->id
                || ($value['document_source_version'] ?? null) !== $documentFact->sourceVersion || $approval->evidenceIds === []) {
                continue;
            }
            if (($section !== null && isset($value['section']) && $value['section'] !== $section)
                || ($crossEntity && ($value['document_applicability_confirmed'] ?? false) !== true)) {
                continue;
            }
            if ($crossEntity || isset($value['document_binding_version'])) {
                $target = $entities[$targetEntityId] ?? null;
                $source = $entities[$documentFact->entityId] ?? null;
                $targetKind = $target?->attributes['semantic_type'] ?? $target?->attributes['properties']['semantic_type'] ?? $target?->type;
                $sourceKind = $source?->attributes['semantic_type'] ?? $source?->attributes['properties']['semantic_type'] ?? $source?->type;
                if ($target === null || $source === null || $target->sourceVersion !== $approval->sourceVersion || $source->sourceVersion !== $documentFact->sourceVersion
                    || [$target->organizationId, $target->projectId, $target->sessionId] !== [$approval->organizationId, $approval->projectId, $approval->sessionId]
                    || [$source->organizationId, $source->projectId, $source->sessionId] !== [$documentFact->organizationId, $documentFact->projectId, $documentFact->sessionId]
                    || ($value['document_binding_version'] ?? null) !== self::BINDING_VERSION
                    || ($value['document_entity_id'] ?? null) !== $documentFact->entityId || ($value['document_fact_version'] ?? null) !== $documentFact->version
                    || ! is_string($value['document_value_hash'] ?? null) || ! hash_equals(self::valueHash($documentFact), $value['document_value_hash'])
                    || ! is_string($targetKind) || ! is_string($sourceKind)
                    || ! FactVocabulary::documentSupportsEntity($documentFact->type, $targetKind) || ! FactVocabulary::documentSupportsEntity($documentFact->type, $sourceKind)
                    || ($crossEntity && ($section === null || ($value['section'] ?? null) !== $section))) {
                    continue;
                }
            }
            $decision = $decisions[$approval->id] ?? null;
            if (! $decision instanceof Decision || $decision->actorType !== 'user' || ! ctype_digit($decision->actorId)
                || $decision->organizationId !== $approval->organizationId || $decision->projectId !== $approval->projectId
                || $decision->sessionId !== $approval->sessionId || $decision->sourceVersion !== $approval->sourceVersion
                || $decision->targetType !== 'fact' || $decision->targetId !== ($approval->supersedesFactId ?? $approval->id) || ($value['decision_id'] ?? null) !== $decision->id
                || ($value['reason'] ?? null) !== $decision->reason) {
                continue;
            }
            if (! (new EvaluationChoiceProof)->accepted($snapshot, $approval, $decision)) {
                continue;
            }
            foreach ($approval->evidenceIds as $id) {
                if (! isset($evidence[$id]) || $evidence[$id]->sourceType !== 'user_input' || $evidence[$id]->sourceVersion !== $approval->sourceVersion) {
                    continue 2;
                }
            }
            foreach ($documentFact->evidenceIds as $id) {
                if (! isset($evidence[$id]) || $evidence[$id]->sourceType !== 'document' || $evidence[$id]->sourceVersion !== $documentFact->sourceVersion
                    || ($value['document_source_ref'] ?? null) !== ($evidence[$id]->sourceReference ?? $evidence[$id]->sourceArtifactId)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }
}
