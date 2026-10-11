<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;

final class RequirementApplicability
{
    public function notApplicable(ProjectModelSnapshot $snapshot, array $decisions, string $entityId, string $requirement, string $technologyKey): ?array
    {
        $evidence = array_column($snapshot->evidence, null, 'id');
        $decisions = array_column(array_filter($decisions, static fn ($decision): bool => $decision instanceof Decision), null, 'selectedFactId');
        foreach ($snapshot->facts as $fact) {
            if ($fact->entityId !== $entityId || $fact->type !== 'evaluation_requirement.'.$requirement
                || $fact->status !== 'confirmed' || $fact->origin !== 'user_input' || ! is_array($fact->value)
                || ($fact->value['disposition'] ?? null) !== 'not_applicable'
                || ($fact->value['scope_version'] ?? null) !== ScopeCompletenessEvaluator::VERSION
                || ($fact->value['technology_key'] ?? null) !== $technologyKey || $fact->evidenceIds === []) {
                continue;
            }
            $decision = $decisions[$fact->id] ?? null;
            if (! $decision instanceof Decision || $decision->actorType !== 'user' || ! ctype_digit($decision->actorId)
                || $decision->targetType !== 'fact' || $decision->targetId !== $fact->id
                || $decision->organizationId !== $fact->organizationId || $decision->projectId !== $fact->projectId
                || $decision->sessionId !== $fact->sessionId || $decision->sourceVersion !== $fact->sourceVersion
                || ($fact->value['decision_id'] ?? null) !== $decision->id
                || ($fact->value['reason'] ?? null) !== $decision->reason) {
                continue;
            }
            foreach ($fact->evidenceIds as $id) {
                $proof = $evidence[$id] ?? null;
                if ($proof === null || $proof->sourceVersion !== $fact->sourceVersion || $proof->sourceType !== 'user_input') {
                    continue 2;
                }
            }

            return ['fact_ids' => [$fact->id], 'decision_id' => $decision->id, 'reason' => $decision->reason,
                'technology_key' => $technologyKey, 'disposition' => 'not_applicable'];
        }

        return null;
    }
}
