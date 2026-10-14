<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceNode;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final class ManualRoomIdentityFactEligibility
{
    /** @param array<int, EvidenceNode> $nodes */
    public function proof(ProjectModelSnapshot $snapshot, Fact $fact, Entity $entity, ?Decision $decision, array $nodes): ?EvidenceNode
    {
        if ($entity->type !== 'room' || $fact->entityId !== $entity->id || $fact->type !== 'entity_identity'
            || $fact->unit !== null || ! is_array($fact->value) || $decision === null
            || ($fact->value['answer_kind'] ?? null) !== 'entity_identity' || ($fact->value['entity_id'] ?? null) !== $entity->id
            || ($fact->value['entity_type'] ?? null) !== 'room' || ($fact->value['identity_confirmed'] ?? null) !== true
            || ! is_string($fact->value['name'] ?? null) || trim($fact->value['name']) === '' || mb_strlen($fact->value['name']) > 160
            || [$entity->organizationId, $entity->projectId, $entity->sessionId] !== [$fact->organizationId, $fact->projectId, $fact->sessionId]
            || ! (new EvaluationChoiceProof)->accepted($snapshot, $fact, $decision)) {
            return null;
        }
        $hash = hash('sha256', CanonicalPipelineJson::encode($fact->value));
        foreach ($fact->evidenceIds as $id) {
            if (preg_match('/\Aevidence:([1-9][0-9]*)\z/', $id, $match) !== 1) {
                continue;
            }
            $node = $nodes[(int) $match[1]] ?? null;
            if ($node !== null && $node->invalidatedAt === null && $node->type === EvidenceType::SourceFact
                && $node->sourceType === EvidenceSourceType::UserInput && $node->sourceVersion === $fact->sourceVersion
                && $node->sourceRef === 'input:'.$fact->sessionId && $node->producerName === EvidenceProducer::UserInputNormalizer->value
                && [$node->organizationId, $node->projectId, $node->sessionId] === [$fact->organizationId, $fact->projectId, $fact->sessionId]
                && ($node->value['fact_key'] ?? null) === 'evaluation_choice' && ($node->value['fact_value'] ?? null) === $hash) {
                return $node;
            }
        }

        return null;
    }
}
