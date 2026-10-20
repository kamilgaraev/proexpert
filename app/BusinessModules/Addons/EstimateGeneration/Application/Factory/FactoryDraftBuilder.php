<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Factory;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\FrozenWorkItemLimit;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationScenarioSourceBinding;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceMaterializer;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceVerifier;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentGenerationPipelineDataGateway;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CanonicalTechnologyWorkItemPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlanCompiler;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CanonicalWorkItemQuantityBinder;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\EquipmentWorkItemExpander;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\FoundationWorkItemExpander;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\RoomFloorWorkItemExpander;
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use Illuminate\Validation\ValidationException;

final readonly class FactoryDraftBuilder
{
    public const CONTRACT = 'factory-draft-materialization:v1';

    private const MAX_ROWS = 1000;

    public function __construct(private ProjectModelRepository $models, private EvaluationInputFingerprint $inputs,
        private CurrentProjectDerivedQuantityService $quantities, private EloquentGenerationPipelineDataGateway $documents,
        private ConstructionSemanticParser $parser, private WorkPlanCompiler $compiler,
        private AcceptedQuantityEvidenceMaterializer $evidence, private AcceptedQuantityEvidenceVerifier $verifier,
        private CanonicalTechnologyWorkItemPlanner $technology = new CanonicalTechnologyWorkItemPlanner,
        private CanonicalWorkItemQuantityBinder $binder = new CanonicalWorkItemQuantityBinder,
        private RoomFloorWorkItemExpander $floors = new RoomFloorWorkItemExpander,
        private EquipmentWorkItemExpander $equipment = new EquipmentWorkItemExpander,
        private FoundationWorkItemExpander $foundations = new FoundationWorkItemExpander,
        private \App\BusinessModules\Addons\EstimateGeneration\Quantities\WallWorkItemExpander $walls = new \App\BusinessModules\Addons\EstimateGeneration\Quantities\WallWorkItemExpander,
        private UniversalDraftProjector $projector = new UniversalDraftProjector,
        private UniversalEvaluationBuilder $evaluations = new UniversalEvaluationBuilder,
        private UniversalReviewSummaryProjector $reviews = new UniversalReviewSummaryProjector,
        private BuildMostEstimateDraft $artifacts = new BuildMostEstimateDraft) {}

    public function build(EstimateGenerationSession $session, array $capture): array
    {
        if (($session->draft_payload ?? []) !== []) {
            throw ValidationException::withMessages(['draft' => trans_message('estimate_generation.factory.draft_exists')]);
        }
        $snapshot = $capture['snapshot'];
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $payload = FactoryWorkingInput::fromPayload($session->input_payload ?? []);
        $decisions = $this->models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        if (! (new EvaluationScenarioSourceBinding)->current($payload['scenario_selections'] ?? null, $snapshot, $decisions)) {
            throw ValidationException::withMessages(['draft' => trans_message('estimate_generation.factory.scenarios_changed')]);
        }
        $projection = $this->quantities->simulate($snapshot, $capture['model_token'], $capture['technology'], $capture['completeness'], $decisions);
        $blocked = array_intersect(array_column($projection['warnings'], 'code'), [
            'quantity_fact_budget_exceeded', 'quantity_decision_budget_exceeded',
            'quantity_formula_budget_exceeded', 'stage5_projection_not_current',
        ]) !== [];
        if ($blocked || ! isset($projection['context']['input_snapshot_hash'], $projection['context']['source_version'])) {
            throw ValidationException::withMessages(['draft' => trans_message('estimate_generation.factory.planning_not_current')]);
        }
        $quantities = $projection['quantities'];
        $planning = $projection['context'];
        $hash = $this->inputs->fromSession($session);
        $version = 'sha256:'.$hash;
        $context = new PipelineContext($scope[2], $scope[0], $scope[1], (int) $session->state_version, $version,
            $session->status->value, baseInputVersion: $version);
        $analysis = $this->parser->parse($payload, $this->documents->documentsForScope(...$scope));
        $analysis['evaluation_policy'] = ['mode' => 'universal',
            'price_policy' => $payload['price_policy'] ?? 'normative', 'profile_id' => $payload['profile_id'] ?? 'construction',
            'selected_sections' => $payload['selected_sections'] ?? [], 'construction_type' => $payload['construction_type'] ?? 'new_construction',
            'scope_selection' => $payload['scope_selection'] ?? null, 'regional_price_selection' => $payload['regional_price_selection'] ?? null,
            'scenario_selections' => $payload['scenario_selections'] ?? null, 'currency_selections' => $payload['currency_selections'] ?? [],
            'contingency_selection' => $payload['contingency_selection'] ?? null, 'technology_selections' => $payload['technology_selections'] ?? [],
            'technology_revision' => $payload['technology_selection_revision'] ?? 0,
            'required_technology_revision' => $payload['technology_selection_revision'] ?? 0];
        $analysis['effective_project_model_values'] = [];
        foreach ($snapshot->facts as $fact) {
            if ($fact->status === 'confirmed') {
                $analysis['effective_project_model_values'][] = ['entity_stable_key' => $fact->entityId,
                    'assertion_stable_key' => $fact->id, 'assertion_type' => $fact->type,
                    'value' => $fact->unit === null ? ['value' => $fact->value] : ['value' => $fact->value, 'unit' => $fact->unit],
                    'version' => $fact->version, 'source_version' => $fact->sourceVersion];
            }
        }
        $analysis['document_context']['canonical_building_quantities'] = array_map(static fn (QuantityData $quantity): array => $quantity->toArray(), array_values($quantities));
        $analysis['document_context']['quantity_coverage_warnings'] = $projection['warnings'];
        $draft = $this->compiler->compile($analysis, null, true, self::MAX_ROWS);
        $draft['quantity_coverage_warnings'] = $projection['warnings'];
        $items = $this->technology->planPackages($planning['work_packages'] ?? []);
        if ($items !== []) {
            $draft['local_estimates'][] = ['key' => 'stage5-technology-packages',
                'title' => trans_message('estimate_generation.stage6.technology_packages_title'), 'scope_type' => 'technology',
                'sections' => [['key' => 'stage5-technology-packages',
                    'title' => trans_message('estimate_generation.stage6.technology_packages_section'), 'work_items' => $items]]];
        }
        $draft = $this->floors->expand($draft, $quantities, self::MAX_ROWS, $planning['room_floor_entity_ids'] ?? []);
        $draft = $this->equipment->expand($draft, $quantities, self::MAX_ROWS, $planning['equipment_estimate_scopes'] ?? []);
        $draft = $this->foundations->expand($draft, $snapshot, self::MAX_ROWS, $quantities, GeometryInventory::currentDocumentVersions($snapshot));
        $documentVersions = GeometryInventory::currentDocumentVersions($snapshot);
        $draft = $this->walls->expand($draft, $snapshot,
            \App\BusinessModules\Addons\EstimateGeneration\Quantities\WallEstimateScope::select($snapshot, $decisions, $documentVersions),
            $quantities, self::MAX_ROWS, $documentVersions);
        $openingSnapshot = \App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryRelationOverlay::factIds($snapshot) === [] ? $snapshot
            : \App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryRelationOverlay::apply($snapshot, $decisions, $documentVersions);
        $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningWorkItemExpander)->expand($draft, $openingSnapshot,
            \App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningEstimateScope::select($openingSnapshot, $decisions, $documentVersions),
            $quantities, self::MAX_ROWS, $documentVersions);
        FrozenWorkItemLimit::assertDraft($draft, self::MAX_ROWS);
        $identity = ['input_snapshot_hash' => $planning['input_snapshot_hash'], 'evaluation_mode' => 'universal',
            'scope_identity' => ['organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2], 'source_version' => $planning['source_version']]];
        foreach ($draft['local_estimates'] as $localIndex => $local) {
            foreach ($local['sections'] as $sectionIndex => $section) {
                foreach ($section['work_items'] as $itemIndex => $item) {
                    $item = $this->binder->bind($item, $quantities, generationIdentity: $identity);
                    $quantity = $item['quantity_evidence'] ?? null;
                    if (is_array($quantity) && ($quantity['review_blockers'] ?? []) === []) {
                        $node = $this->evidence->materialize($context, QuantityData::fromArray($quantity), $item);
                        $item['quantity_evidence_id'] = $node->id;
                        $item['quantity_evidence_fingerprint'] = $node->fingerprint;
                        $item['quantity_evidence_source_version'] = $node->sourceVersion;
                    }
                    $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $item;
                }
            }
        }
        $draft = $this->projector->project($draft, fn (array $item): bool => $this->verifier->verify($context, $item));
        $draft['source_input_version'] = $version;
        $draft['evaluation_result'] = $this->evaluations->build($draft, $snapshot, $decisions, $payload['technology_selections'] ?? []);
        $draft['is_complete'] = false;
        $draft['stage6_status'] = 'scenario_confirmation_required';

        return ['draft' => $this->artifacts->seal($this->reviews->project($draft)), 'input_hash' => $hash];
    }

    public static function stop(string $outcome, string $sourceCheckpointId, int $stateVersion, array $limitations = []): array
    {
        return ['step' => 'materialize_draft', 'outcome' => $outcome, 'source_checkpoint_id' => $sourceCheckpointId,
            'result_state_version' => $stateVersion, 'draft_refs' => null, 'question_refs' => [], 'total_questions' => 0,
            'next_action' => 'stop', 'limitations' => $limitations, 'estimate_complete' => false,
            'paid_actions_enabled' => false, 'cost_units' => '0'];
    }
}
