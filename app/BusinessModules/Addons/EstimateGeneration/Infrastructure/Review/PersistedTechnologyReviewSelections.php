<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Review;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelInvariant;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Planning\OrganizationPreferenceContext;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendation;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendationService;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog;
use InvalidArgumentException;

final readonly class PersistedTechnologyReviewSelections
{
    public function __construct(private ProjectModelRepository $models, private TechnologySystemCatalog $catalog,
        private TechnologyRecommendationService $recommendations) {}

    /** @param list<TechnologyRecommendation> $currentRecommendations */
    public function current(EstimateGenerationSession $session, ProjectModelSnapshot $snapshot, int $limit, array $currentRecommendations = []): array
    {
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $currentByKey = array_column($currentRecommendations, null, 'decisionKey');
        $groups = [];
        foreach ($snapshot->facts as $fact) {
            if (is_array($fact->value) && in_array($fact->value['kind'] ?? null, ['catalog_system', 'other'], true)
                && in_array($fact->type, ['material', 'material_name', 'roof_covering_system'], true)) {
                $key = 'roof_covering_system.'.substr(hash('sha256', $fact->entityId), 0, 24);
                $groups[$key][] = $fact;
            }
        }
        $limit = max(1, min($limit, 1001));
        $overflow = count($snapshot->facts) > 10000;
        $truncated = $overflow || count($groups) > $limit;
        $groups = array_slice($groups, 0, $limit, true);
        $ids = array_merge([], ...array_map(static fn (array $facts): array => array_column($facts, 'id'), array_values($groups)));
        $decisions = $ids === [] || $overflow ? [] : $this->models->decisionsForSelectedFacts(...[...$scope, $ids]);
        $byFact = [];
        $targetIds = [];
        $previousDecisionIds = [];
        foreach ($groups as $facts) {
            foreach ($facts as $fact) {
                if (is_string($fact->value['previous_fact_id'] ?? null) && is_string($fact->value['previous_decision_id'] ?? null)) {
                    try {
                        ProjectModelInvariant::id($fact->value['previous_fact_id'], 'Fact');
                        ProjectModelInvariant::id($fact->value['previous_decision_id'], 'Decision');
                    } catch (InvalidArgumentException) {
                        continue;
                    }
                    $targetIds[] = $fact->value['previous_fact_id'];
                    $previousDecisionIds[] = $fact->value['previous_decision_id'];
                }
            }
        }
        foreach ($decisions as $decision) {
            if ($this->scope($decision) === $scope && $decision->targetType === 'fact' && $decision->selectedFactId !== null) {
                $byFact[$decision->selectedFactId][] = $decision;
                $targetIds[] = $decision->targetId;
            }
        }
        $targets = $targetIds === [] || $overflow ? [] : array_column($this->models->factsByIds(...[...$scope, array_values(array_unique($targetIds))]), null, 'id');
        $previousDecisions = $previousDecisionIds === [] || $overflow ? [] : array_column(
            $this->models->decisions(...[...$scope, array_values(array_unique($previousDecisionIds))]), null, 'id');
        $entities = array_column($snapshot->entities, null, 'id');
        $conflicted = [];
        foreach ($snapshot->conflicts as $conflict) {
            if ($conflict->status === 'unresolved') {
                foreach ($conflict->facts as $fact) {
                    $conflicted[$fact->entityId][$fact->type] = true;
                }
            }
        }
        $items = [];
        foreach ($groups as $key => $facts) {
            $item = ['decision_key' => $key, 'recommendation' => null, 'selected_option' => null,
                'selection_state' => 'requires_review', 'reason' => null,
                'evidence_ids' => array_values(array_unique(array_merge([], ...array_column($facts, 'evidenceIds'))))];
            if ($overflow || count($facts) !== 1) {
                $items[] = $item;

                continue;
            }
            $fact = $facts[0];
            $linked = $byFact[$fact->id] ?? [];
            if (count($linked) !== 1) {
                $items[] = $item;

                continue;
            }
            $decision = $linked[0];
            $target = $targets[$decision->targetId] ?? null;
            if ($target === null || $this->scope($fact) !== $scope || $this->scope($target) !== $scope
                || $decision->sourceVersion !== $fact->sourceVersion || $target->sourceVersion !== $fact->sourceVersion
                || ! $this->linkedTarget($fact, $target, $targets, $previousDecisions)
                || $target->entityId !== $fact->entityId || $target->type !== $fact->type
                || ! isset($entities[$fact->entityId]) || ($fact->value['decision_key'] ?? null) !== $key) {
                $items[] = $item;

                continue;
            }
            try {
                $sourceSnapshot = new ProjectModelSnapshot($snapshot->entities,
                    array_values(array_filter($snapshot->facts, static fn (Fact $candidate): bool => $candidate->sourceVersion === $target->sourceVersion)),
                    $snapshot->evidence, $snapshot->conflicts);
                $recommendation = $this->recommendations->recommend($sourceSnapshot, $target, new OrganizationPreferenceContext($scope[0], []));
            } catch (InvalidArgumentException) {
                $items[] = $item;

                continue;
            }
            $item['recommendation'] = $recommendation;
            $item['reason'] = $decision->reason;
            $systemId = $fact->value['system_id'] ?? null;
            $active = $currentByKey[$key] ?? $recommendation;
            $sameTarget = [$active->organizationId, $active->projectId, $active->sessionId, $active->targetFactId,
                $active->sourceVersion, $active->catalogVersion, $active->catalogHash]
                === [...$scope, $recommendation->targetFactId, $recommendation->sourceVersion,
                    $recommendation->catalogVersion, $recommendation->catalogHash];
            $applicable = $this->applicable($recommendation, $systemId) && $this->applicable($active, $systemId);
            if ($fact->origin === 'user_assumption' && $fact->status === 'confirmed' && $decision->actorType === 'user'
                && ($fact->value['kind'] ?? null) === 'catalog_system' && is_string($systemId)
                && ($session->input_payload['technology_selections']['roofing'][$fact->entityId] ?? null) === $systemId
                && ($fact->value['catalog_version'] ?? null) === $this->catalog->version
                && ($fact->value['catalog_hash'] ?? null) === $this->catalog->contentHash
                && $recommendation->decisionKey === $key && $sameTarget && $applicable && ! isset($conflicted[$fact->entityId][$fact->type])) {
                $item['selected_option'] = $systemId;
                $item['selection_state'] = 'selected';
            }
            $items[] = $item;
        }

        return ['items' => $items, 'truncated' => $truncated];
    }

    private function applicable(TechnologyRecommendation $recommendation, mixed $systemId): bool
    {
        foreach ($recommendation->options as $option) {
            if ($option->system->id === $systemId && $option->applicabilityStatus === 'applicable') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, Fact> $facts @param array<string, Decision> $decisions */
    private function linkedTarget(Fact $fact, Fact $target, array $facts, array $decisions): bool
    {
        $previousFactId = $fact->value['previous_fact_id'] ?? null;
        $previousDecisionId = $fact->value['previous_decision_id'] ?? null;
        if ($previousFactId === null && $previousDecisionId === null) {
            return $fact->supersedesFactId === $target->id;
        }
        if (! is_string($previousFactId) || ! is_string($previousDecisionId)) {
            return false;
        }
        $previous = $facts[$previousFactId] ?? null;
        $decision = $decisions[$previousDecisionId] ?? null;

        return $previous !== null && $decision !== null && $fact->supersedesFactId === $previous->id
            && $this->scope($previous) === $this->scope($fact) && $this->scope($decision) === $this->scope($fact)
            && $previous->sourceVersion === $fact->sourceVersion && $decision->sourceVersion === $fact->sourceVersion
            && $previous->entityId === $fact->entityId && $previous->type === $fact->type
            && $previous->origin === 'user_assumption' && $previous->status === 'confirmed'
            && is_array($previous->value) && ($previous->value['kind'] ?? null) === 'catalog_system'
            && ($previous->value['decision_key'] ?? null) === ($fact->value['decision_key'] ?? null)
            && $decision->actorType === 'user' && $decision->targetType === 'fact'
            && $decision->selectedFactId === $previous->id && $decision->targetId === $target->id;
    }

    /** @return array{int, int, int} */
    private function scope(Fact|Decision $record): array
    {
        return [$record->organizationId, $record->projectId, $record->sessionId];
    }
}
