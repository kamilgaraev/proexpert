<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

final class TechnologyReselectionInvariant
{
    public function matches(ProjectModelRepository $models, Decision $decision, Fact $selected, string $expectedFactId,
        string $expectedDecisionId, string $inputFingerprint): bool
    {
        ProjectModelInvariant::sameScope($decision, $selected);
        $scope = [$decision->organizationId, $decision->projectId, $decision->sessionId];
        $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
        if (count($capture['snapshot']->facts) > 10000 || ! hash_equals($inputFingerprint, $capture['token'])
            || $decision->selectedFactId !== $selected->id || $selected->supersedesFactId !== $expectedFactId
            || $decision->actorType !== 'user' || $selected->origin !== 'user_assumption' || $selected->status !== 'confirmed'
            || ! is_array($selected->value) || ($selected->value['kind'] ?? null) !== 'catalog_system'
            || ($selected->value['previous_fact_id'] ?? null) !== $expectedFactId
            || ($selected->value['previous_decision_id'] ?? null) !== $expectedDecisionId) {
            return false;
        }
        $previous = $models->decisions(...[...$scope, [$expectedDecisionId]])[0] ?? null;
        foreach ($models->currentFacts(...[...$scope, $selected->entityId]) as $current) {
            if ($current->id === $expectedFactId) {
                return $current->origin === 'user_assumption' && $current->status === 'confirmed' && is_array($current->value)
                    && ($current->value['kind'] ?? null) === 'catalog_system' && $current->type === $selected->type
                    && $current->sourceVersion === $selected->sourceVersion && $selected->version === $current->version + 1
                    && $previous instanceof Decision && $previous->selectedFactId === $current->id
                    && $previous->targetId === $decision->targetId && $previous->sourceVersion === $decision->sourceVersion;
            }
        }

        return false;
    }
}
