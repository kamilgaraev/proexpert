<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final class EvaluationChoiceProof
{
    public function accepted(ProjectModelSnapshot $snapshot, Fact $fact, Decision $decision): bool
    {
        if ($fact->origin !== 'user_input' || $fact->status !== 'confirmed' || $fact->evidenceIds === []
            || ! is_array($fact->value) || $decision->selectedFactId !== $fact->id || $decision->actorType !== 'user'
            || ! ctype_digit($decision->actorId) || $decision->targetType !== 'fact'
            || $decision->targetId !== ($fact->supersedesFactId ?? $fact->id)
            || ($fact->value['decision_id'] ?? null) !== $decision->id || ($fact->value['reason'] ?? null) !== $decision->reason) {
            return false;
        }
        foreach (['organizationId', 'projectId', 'sessionId', 'sourceVersion'] as $field) {
            if ($decision->$field !== $fact->$field) {
                return false;
            }
        }
        $hash = hash('sha256', CanonicalPipelineJson::encode($fact->value));
        $proofs = array_column($snapshot->evidence, null, 'id');
        foreach ($fact->evidenceIds as $id) {
            $proof = $proofs[$id] ?? null;
            if ($proof === null || ! in_array($id, $decision->evidenceIds, true) || $proof->sourceType !== 'user_input'
                || $proof->producerName !== EvidenceProducer::UserInputNormalizer->value
                || $proof->sourceReference !== 'input:'.$fact->sessionId || $proof->choiceHash === null || ! hash_equals($hash, $proof->choiceHash)) {
                return false;
            }
            foreach (['organizationId', 'projectId', 'sessionId', 'sourceVersion'] as $field) {
                if ($proof->$field !== $fact->$field) {
                    return false;
                }
            }
        }

        return true;
    }
}
