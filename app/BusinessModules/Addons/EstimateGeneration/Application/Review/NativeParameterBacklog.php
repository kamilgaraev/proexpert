<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Review;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ScopeCompletenessEvaluator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\ScopedQuantityInputReadiness;
use Illuminate\Validation\ValidationException;

final readonly class NativeParameterBacklog
{
    public function __construct(private ProjectModelRepository $models, private CurrentProjectDerivedQuantityService $quantities,
        private NativeParameterQuestionProjector $projector) {}

    public function current(EstimateGenerationSession $session, ProjectModelSnapshot $snapshot, string $token): array
    {
        $payload = $session->input_payload ?? [];
        $sections = $payload['selected_sections'] ?? null;
        $validScope = is_array($sections) && array_is_list($sections) && count($sections) >= 1 && count($sections) <= 12
            && count(array_unique(array_filter($sections, 'is_string'))) === count($sections);
        if ($validScope) {
            foreach ($sections as $section) {
                $validScope = $validScope && ScopeCompletenessEvaluator::requirementsFor($section, 'new_construction', 'construction') !== [];
            }
        }
        if (! config('estimate-generation.universal_enabled', false) || ($payload['evaluation_mode'] ?? null) !== 'universal' || ! $validScope) {
            return $this->unavailable('native_scope_unavailable');
        }
        if (count($snapshot->facts) > 10000) {
            return $this->unavailable('input_budget_exceeded');
        }
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $decisions = $this->models->decisionsForSelectedFacts(...[...$scope,
            \App\BusinessModules\Addons\EstimateGeneration\Quantities\NativeQuantityDecisionInputs::factIds($snapshot)]);
        if (count($decisions) > 256) {
            return $this->unavailable('input_budget_exceeded');
        }
        $simulation = $this->quantities->simulateInput($snapshot, $token, $decisions);
        foreach ($simulation['warnings'] as $warning) {
            if (in_array($warning['code'] ?? null, ['quantity_input_simulation_budget_exceeded', 'quantity_formula_budget_exceeded'], true)) {
                return $this->unavailable('input_budget_exceeded');
            }
        }
        sort($sections, SORT_STRING);
        $documentVersions = GeometryInventory::currentDocumentVersions($snapshot);
        $warnings = array_values(array_filter($simulation['warnings'], static fn (array $warning): bool => ! ScopedQuantityInputReadiness::permitsPartialPlanning($warning, $snapshot, $token, $sections,
            $simulation['quantities'], $documentVersions, $decisions)));

        $backlog = $this->projector->project($snapshot, $token, $sections, $warnings,
            $documentVersions, (int) $session->state_version, EstimatePrerequisiteBacklog::editable($session),
            array_intersect_key($payload, array_flip(['building_type', 'floors'])));

        return ($session->draft_payload ?? []) === [] ? $backlog
            : (new WallAssemblyQuestions)->append($backlog, $snapshot, $decisions, $documentVersions,
                $token, $sections, (int) $session->state_version, EstimatePrerequisiteBacklog::editable($session));
    }

    public function validateAnswer(EstimateGenerationSession $session, ProjectModelSnapshot $snapshot, string $token, array $input): void
    {
        $rows = $input['entities'] ?? [];
        $bound = array_filter($rows, static fn (array $row): bool => array_key_exists('native_question', $row));
        if ($bound === []) {
            return;
        }
        $backlog = $this->current($session, $snapshot, $token);
        $row = reset($bound);
        $binding = $row['native_question'];
        foreach ($backlog['questions'] as $question) {
            $answer = $question['answer'];
            if (count($rows) === 1 && $question['can_answer'] && is_array($binding)
                && CanonicalPipelineJson::encode($binding) === CanonicalPipelineJson::encode($answer['native_question'])
                && ($row['existing_entity_id'] ?? null) === $answer['entity_id'] && ($row['type'] ?? null) === $answer['entity_type']
                && ((($answer['kind'] ?? null) === 'project_parameter'
                        && array_keys($row['parameters'] ?? []) === [$answer['parameter']]
                        && array_diff(array_keys($row), ['key', 'type', 'existing_entity_id', 'parameters', 'native_question']) === [])
                    || (($answer['kind'] ?? null) === 'wall_assembly' && is_array($row['wall_assembly'] ?? null)
                        && ($row['wall_assembly']['entity_fingerprint'] ?? null) === $answer['entity_fingerprint']
                        && array_diff(array_keys($row), ['key', 'type', 'existing_entity_id', 'wall_assembly', 'native_question']) === []))) {
                return;
            }
        }
        throw ValidationException::withMessages(['entities' => trans_message('estimate_generation.native_questions.changed')]);
    }

    private function unavailable(string $reason): array
    {
        return ['contract_version' => NativeParameterQuestionProjector::CONTRACT, 'available' => false,
            'input_fingerprint' => null, 'questions' => [], 'total_questions' => 0, 'truncated' => false,
            'limitations' => [$reason], 'cost_units' => '0'];
    }
}
