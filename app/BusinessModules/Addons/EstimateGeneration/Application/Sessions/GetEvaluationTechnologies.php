<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Planning\OrganizationPreferenceContext;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendation;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendationService;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemOption;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class GetEvaluationTechnologies
{
    public function __construct(private EstimateGenerationActionAuthorization $authorization, private ProjectModelRepository $models,
        private TechnologySystemCatalog $catalog, private TechnologyRecommendationService $recommendations) {}

    public function handle(User $actor, int $projectId, int $sessionId, int $page = 1): array
    {
        $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
            ->where('project_id', $projectId)->whereKey($sessionId)->firstOrFail();
        $this->authorization->authorize($actor, $session, 'estimate_generation.view');
        if ($page < 1 || $page > 100000) {
            throw ValidationException::withMessages(['page' => trans_message('estimate_evaluation.source_search_invalid')]);
        }
        $scope = [(int) $session->organization_id, $projectId, $sessionId];
        $current = $this->models->currentTechnologyRecommendations(...$scope);
        $snapshot = $this->models->snapshotForPlanning(...[...$scope, 10001]);
        if (count($snapshot['snapshot']->facts) > 10000) {
            throw ValidationException::withMessages(['technology' => trans_message('estimate_evaluation.technology_fact_limit')]);
        }
        $fresh = $current !== null && ($current['is_current'] ?? false) === true
            && count($snapshot['snapshot']->facts) <= 10000 && hash_equals((string) $current['input_fingerprint'], $snapshot['token']);
        $recommendations = [];
        $entities = array_column($snapshot['snapshot']->entities, null, 'id');
        if ($fresh) {
            foreach ($current['recommendations'] as $recommendation) {
                if (! $recommendation instanceof TechnologyRecommendation) {
                    continue;
                }
                $target = $this->models->fact(...[...$scope, $recommendation->targetFactId]);
                if ($target === null || ! str_starts_with($recommendation->decisionKey, 'roof_covering_system.')) {
                    continue;
                }
                $recommendations[] = ['decision_key' => $recommendation->decisionKey, 'planning_run_id' => $current['run_id'],
                    'source_version' => $recommendation->sourceVersion, 'entity_id' => $target->entityId, 'section' => 'roofing',
                    'entity_label' => $entities[$target->entityId]->attributes['name'] ?? null,
                    'question' => $recommendation->question, 'conditional' => $recommendation->conditional,
                    'options' => array_map(static fn (TechnologySystemOption $option): array => ['system_id' => $option->system->id, 'label' => $option->label,
                        'explanation' => $option->explanation, 'applicability' => $option->applicabilityStatus,
                        'reasons' => $option->applicabilityReasons, 'recommended' => $option->recommended], $recommendation->options)];
            }
        }

        $systems = array_column($this->catalog->systems, null, 'id');
        $selectedFacts = [];
        foreach ($snapshot['snapshot']->facts as $fact) {
            if ($fact->origin === 'user_assumption' && $fact->status === 'confirmed' && is_array($fact->value)
                && ($fact->value['kind'] ?? null) === 'catalog_system' && is_string($fact->value['system_id'] ?? null)
                && ($session->input_payload['technology_selections']['roofing'][$fact->entityId] ?? null) === $fact->value['system_id']) {
                $selectedFacts[$fact->id] = $fact;
            }
        }
        $selected = [];
        foreach ($selectedFacts === [] ? [] : $this->models->decisionsForSelectedFacts(...[...$scope, array_keys($selectedFacts)]) as $decision) {
            $fact = $selectedFacts[$decision->selectedFactId ?? ''] ?? null;
            if ($fact === null) {
                continue;
            }
            $system = $systems[$fact->value['system_id']] ?? null;
            $currentCatalog = $system !== null && ($fact->value['catalog_version'] ?? null) === $this->catalog->version
                && ($fact->value['catalog_hash'] ?? null) === $this->catalog->contentHash;
            $options = [];
            $original = $this->models->fact(...[...$scope, $decision->targetId]);
            if ($original !== null && $original->entityId === $fact->entityId && $original->type === $fact->type
                && $original->sourceVersion === $fact->sourceVersion) {
                try {
                    $recommendation = $this->recommendations->recommend($snapshot['snapshot'], $original, new OrganizationPreferenceContext($scope[0], []));
                    $options = array_map(static fn (TechnologySystemOption $option): array => ['system_id' => $option->system->id,
                        'label' => $option->label, 'explanation' => $option->explanation, 'applicability' => $option->applicabilityStatus,
                        'reasons' => $option->applicabilityReasons, 'recommended' => $option->recommended], $recommendation->options);
                } catch (InvalidArgumentException) {
                    // Old choices stay visible even when a new applicable option cannot be established.
                }
            }
            $selected[$fact->id] = ['fact_id' => $fact->id, 'decision_id' => $decision->id, 'entity_id' => $fact->entityId,
                'entity_label' => $entities[$fact->entityId]->attributes['name'] ?? null, 'section' => 'roofing',
                'system_id' => $fact->value['system_id'], 'label' => $currentCatalog ? trans_message($system->nameKey) : null,
                'catalog_current' => $currentCatalog, 'reason' => $decision->reason, 'options' => $options];
        }
        $lastPage = max(1, (int) ceil(count($selected) / 25));
        $page = min($page, $lastPage);

        return ['state_version' => (int) $session->state_version, 'cost_units' => '0', 'is_current' => $fresh,
            'selected' => array_slice(array_values($selected), ($page - 1) * 25, 25),
            'pagination' => ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => 25, 'total' => count($selected)],
            'recommendations' => $recommendations, 'composition_refresh_required' => ($session->input_payload['technology_selection_revision'] ?? 0)
                > ($session->draft_payload['evaluation_policy']['technology_revision'] ?? 0)];
    }
}
