<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceNode;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;

final class RoomIdentityFactEligibility
{
    /** @param array<int, EvidenceNode> $nodes */
    public function proof(Fact $fact, Entity $entity, array $nodes): ?EvidenceNode
    {
        if ($entity->type !== 'room' || $fact->entityId !== $entity->id || $fact->type !== 'room_name'
            || $fact->unit !== null || ! in_array($fact->status, ['candidate', 'confirmed'], true)
            || ! in_array($fact->origin, ['document', 'user_assumption'], true)
            || ! is_string($fact->value) || trim($fact->value) === '' || mb_strlen($fact->value) > 240) {
            return null;
        }
        foreach ($fact->evidenceIds as $id) {
            if (preg_match('/\Aevidence:([1-9][0-9]*)\z/', $id, $match) !== 1) {
                continue;
            }
            $node = $nodes[(int) $match[1]] ?? null;
            if ($node !== null && $node->invalidatedAt === null && $node->type === EvidenceType::SourceFact
                && $node->sourceType === EvidenceSourceType::Document && $node->sourceVersion === $fact->sourceVersion
                && [$node->organizationId, $node->projectId, $node->sessionId] === [$fact->organizationId, $fact->projectId, $fact->sessionId]
                && ($node->value['fact_key'] ?? null) === 'room_name'
                && ($node->value['fact_value'] ?? null) === hash('sha256', trim($fact->value))
                && ! array_key_exists('unit', $node->value)
                && is_int($node->locator['document_id'] ?? null)
                && $node->sourceRef === 'document:'.$node->locator['document_id']) {
                return $node;
            }
        }

        return null;
    }
}
