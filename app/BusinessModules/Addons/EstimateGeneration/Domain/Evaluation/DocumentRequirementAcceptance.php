<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;

final class DocumentRequirementAcceptance
{
    public function accepted(ProjectModelSnapshot $snapshot, array $decisions, Fact $documentFact, string $technologyKey): bool
    {
        if ($documentFact->origin !== 'document' || $documentFact->status !== 'confirmed' || $documentFact->evidenceIds === []) {
            return false;
        }
        $evidence = array_column($snapshot->evidence, null, 'id');
        $decisions = array_column(array_filter($decisions, static fn ($decision): bool => $decision instanceof Decision), null, 'selectedFactId');
        foreach ($snapshot->facts as $approval) {
            $value = $approval->value;
            if ($approval->entityId !== $documentFact->entityId || $approval->type !== 'evaluation_requirement.'.$documentFact->type
                || $approval->origin !== 'user_input' || $approval->status !== 'confirmed' || ! is_array($value)
                || ($value['disposition'] ?? null) !== 'document_verified' || ($value['scope_version'] ?? null) !== ScopeCompletenessEvaluator::VERSION
                || ($value['technology_key'] ?? null) !== $technologyKey || ($value['document_fact_id'] ?? null) !== $documentFact->id
                || ($value['document_source_version'] ?? null) !== $documentFact->sourceVersion || $approval->evidenceIds === []) {
                continue;
            }
            $decision = $decisions[$approval->id] ?? null;
            if (! $decision instanceof Decision || $decision->actorType !== 'user' || ! ctype_digit($decision->actorId)
                || $decision->organizationId !== $approval->organizationId || $decision->projectId !== $approval->projectId
                || $decision->sessionId !== $approval->sessionId || $decision->sourceVersion !== $approval->sourceVersion
                || $decision->targetType !== 'fact' || $decision->targetId !== $approval->id || ($value['decision_id'] ?? null) !== $decision->id
                || ($value['reason'] ?? null) !== $decision->reason) {
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
