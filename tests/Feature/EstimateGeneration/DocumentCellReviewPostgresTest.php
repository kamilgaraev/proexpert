<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerAllowance;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\TimewebEstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentFileAdmission;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceManifestStorage;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitContentReader;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDetector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ReviewSpreadsheetCellInterpretation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SeekableDocumentSource;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\StoredDocumentArtifact;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SpreadsheetCellReviewSource;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\InitialProjectFactsWriter;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveEstimateGenerationSettings;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningEvidenceService;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline as Pipeline;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCost;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshotResolver;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EloquentAiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorCreditAttemptAdmission;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\Services\Credits\AICreditService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class DocumentCellReviewPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_public_review_contract_rejects_stale_forged_and_collision_inputs_and_current_reader_excludes_changed_source(): void
    {
        [$session, $actor, $document] = $this->ordinaryWorkbook();
        app(InitialProjectFactsWriter::class)->write($session, $actor);
        $this->composeCells($session, $actor, publish: true);
        $session = $session->fresh();
        $controller = app(\App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationDocumentController::class);
        $reviews = app(ReviewSpreadsheetCellInterpretation::class);
        $get = Request::create('/', 'GET');
        $get->setUserResolver(static fn (): User => $actor);
        $response = $controller->cellReviews($get, $session->project, $session, $document, $reviews);
        self::assertSame(200, $response->getStatusCode());
        $data = $response->getData(true)['data'];
        self::assertSame((int) $session->state_version, $data['state_version']);
        self::assertCount(1, $data['items']);
        $input = $this->reviewRequest($session, $data['items'][0]);
        foreach ([['proposal_hash' => str_repeat('f', 64)], ['source_version' => 'sha256:'.str_repeat('f', 64)],
            ['value' => 'Подставленное значение'], ['decision' => 'revoke', 'identity_confirmation' => null]] as $patch) {
            $attempt = array_replace($input, $patch);
            if ($patch['decision'] ?? null) {
                unset($attempt['identity_confirmation']);
            }
            self::assertSame(422, $this->postReview($actor, $session, $document, $attempt)->getStatusCode());
        }
        self::assertSame(0, EstimateGenerationLearningExample::query()->where('generation_session_id', $session->id)->count());
        $accepted = $this->postReview($actor, $session, $document, $input);
        self::assertSame(200, $accepted->getStatusCode());
        self::assertSame(CanonicalPipelineJson::encode($accepted->getData(true)['data']),
            CanonicalPipelineJson::encode($this->postReview($actor, $session, $document, $input)->getData(true)['data']));
        self::assertSame(422, $this->postReview($actor, $session, $document, [...$input, 'reason' => 'Другая причина для того же запроса.'])->getStatusCode());
        self::assertSame(409, $this->postReview($actor, $session, $document, [...$input, 'request_id' => (string) Str::uuid()])->getStatusCode());
        $other = User::factory()->create(['is_active' => true, 'current_organization_id' => $session->organization_id]);
        $other->organizations()->attach($session->organization_id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        self::assertSame(422, $this->postReview($other, $session, $document, $input)->getStatusCode());
        self::assertSame(1, EstimateGenerationLearningExample::query()->where('generation_session_id', $session->id)->count());
        self::assertSame(1, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $actor->forceFill(['is_active' => false])->save();
        self::assertSame([], app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null, ['equipment'], 'universal', $session->fresh()->input_payload])->sourceClaimReferences);
        $actor->forceFill(['is_active' => true])->save();
        $document->update(['source_version' => 'sha256:'.str_repeat('e', 64), 'checksum_sha256' => str_repeat('e', 64)]);
        $session = $session->fresh();
        self::assertSame([], $reviews->rows($actor, $session, (int) $document->id));
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $capture = app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null, ['equipment'], 'universal', $session->input_payload]);
        self::assertSame([], $capture->sourceClaimReferences);
        self::assertSame([], array_values(array_filter($capture->facts, static fn ($fact): bool => $fact['origin'] === 'document')));
    }

    public function test_review_rollback_and_revoke_preserve_adjacent_views_native_facts_and_derived_projection(): void
    {
        [$session, $actor, $document, $unit] = $this->ordinaryWorkbook(neighbors: true);
        app(InitialProjectFactsWriter::class)->write($session, $actor);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $snapshot = $models->snapshotForPlanning(...[...$scope, 100])['snapshot'];
        $native = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'equipment_count'))[0];
        self::assertNotEmpty($native->nativeNumericProofs);
        $arguments = [...$scope, $native->sourceVersion, $native->entityId, 'technology_work_package:1', [
                ['fact_id' => $native->id, 'projection_version' => $native->version, 'status' => $native->status, 'current' => true,
                    'value' => (string) $native->value, 'unit' => $native->unit, 'evidence_ids' => $native->evidenceIds, 'decision_id' => null],
            ], '4.0000', 'count', 'half_up', 4, $native->evidenceIds, 'confirmed', 'technology_work_package', '1'];
        $prototype = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantity('derived:neighbor', ...$arguments,
            snapshotIdentity: ['source_version' => $native->sourceVersion], logicalId: 'derived:neighbor');
        $identity = \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantityIdentity::for($prototype);
        $derived = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantity('quantityv:'.$identity, ...$arguments,
            snapshotIdentity: ['source_version' => $native->sourceVersion], logicalId: 'derived:neighbor', exactIdentity: $identity);
        $models->appendDerivedQuantities([$derived]);
        $pageId = (int) DB::table('estimate_generation_document_pages')->where('processing_unit_id', $unit->id)->sole()->id;
        foreach (['material', 'total_area'] as $type) {
            \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentFact::query()->create([
                'organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2], 'document_id' => $document->id,
                'page_id' => $pageId, 'fact_type' => $type, 'scope_key' => 'neighbor:'.$type, 'label' => 'Соседняя проекция',
                'value_text' => $type === 'material' ? 'Материал соседней строки' : null,
                'value_number' => $type === 'total_area' ? '12.0000' : null, 'unit' => $type === 'total_area' ? 'm2' : null,
                'confidence' => 0, 'source_ref' => ['source_version' => $document->source_version],
                'normalized_payload' => ['projection' => 'arbiter_consensus:v1', 'projection_key' => 'sha256:'.hash('sha256', $type), 'fact_type' => $type],
            ]);
        }
        \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationQuantityTakeoff::query()->create([
            'organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2], 'document_id' => $document->id,
            'page_id' => $pageId, 'source_element_ids' => [], 'scope_key' => 'rough_floor_area', 'work_intent' => [],
            'name' => 'Существующая соседняя проекция', 'unit' => 'm2', 'quantity' => 12, 'formula' => 'accepted_building_area_total',
            'confidence' => 0, 'source_refs' => [], 'normalized_payload' => ['projection' => 'requested_area_takeoff:v1'],
        ]);
        $this->composeCells($session, $actor, publish: true);
        $session = $session->fresh();
        $reviews = app(ReviewSpreadsheetCellInterpretation::class);
        $rows = $reviews->rows($actor, $session, (int) $document->id);
        self::assertCount(1, $rows);
        $request = $this->reviewRequest($session, $rows[0]);
        $tables = ['estimate_generation_document_facts', 'estimate_generation_quantity_takeoffs',
            'estimate_generation_project_model_assertions', 'estimate_generation_project_model_entities',
            'estimate_generation_project_model_derived_quantities', 'estimate_generation_project_model_derived_quantity_projections'];
        $keys = array_fill_keys($tables, 'id');
        $keys['estimate_generation_project_model_derived_quantity_projections'] = 'logical_key';
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->where('session_id', $scope[2])->orderBy($keys[$table])->get()->map(fn ($row) => (array) $row)->all();
        }
        $fault = true;
        DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$fault): void {
            if ($fault && str_contains(strtolower($query->sql), 'insert into estimate_generation_learning_examples')) {
                throw new \RuntimeException('fixture_cell_review_write_failed');
            }
        });
        try {
            $reviews->handle($actor, $scope[1], $scope[2], (int) $document->id, $request);
            self::fail('A failed final write must roll back the entire review.');
        } catch (\RuntimeException $error) {
            self::assertSame('fixture_cell_review_write_failed', $error->getMessage());
        }
        $fault = false;
        foreach ($before as $table => $records) {
            self::assertSame($records, DB::table($table)->where('session_id', $scope[2])->orderBy($keys[$table])->get()->map(fn ($row) => (array) $row)->all(), $table);
        }
        self::assertSame((int) $session->state_version, (int) $session->fresh()->state_version);
        self::assertSame(0, EstimateGenerationLearningExample::query()->where('generation_session_id', $scope[2])->count());
        $reviews->handle($actor, $scope[1], $scope[2], (int) $document->id, $request);
        $session = $session->fresh();
        $reviews->handle($actor, $scope[1], $scope[2], (int) $document->id, $this->reviewRequest($session, $rows[0], 'revoke'));
        foreach ($before as $table => $records) {
            $preserved = DB::table($table)->where('session_id', $scope[2])->whereIn($keys[$table], array_column($records, $keys[$table]))->orderBy($keys[$table])->get()->map(fn ($row) => (array) $row)->all();
            self::assertSame($records, $preserved, $table);
        }
        $currentFacts = array_column($models->snapshotForPlanning(...[...$scope, 100])['snapshot']->facts, null, 'id');
        self::assertSame(get_object_vars($native), get_object_vars($currentFacts[$native->id]));
        self::assertSame([], app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null, ['equipment'], 'universal', $session->fresh()->input_payload])->sourceClaimReferences);
    }

    public function test_public_review_reason_uses_the_same_unicode_byte_limit_as_the_existing_choice_contract(): void
    {
        [$session, $actor, $document] = $this->ordinaryWorkbook();
        app(InitialProjectFactsWriter::class)->write($session, $actor);
        $this->composeCells($session, $actor, publish: true);
        $session = $session->fresh();
        $reviews = app(ReviewSpreadsheetCellInterpretation::class);
        $row = $reviews->rows($actor, $session, (int) $document->id)[0];
        $input = [...$this->reviewRequest($session, $row), 'reason' => str_repeat('Я', 500)];
        self::assertSame(1000, strlen($input['reason']));
        self::assertSame(200, $this->postReview($actor, $session, $document, $input)->getStatusCode());
        self::assertSame(200, $this->postReview($actor, $session, $document, $input)->getStatusCode());
        try {
            $this->postReview($actor, $session->fresh(), $document, [...$input, 'request_id' => (string) Str::uuid(), 'reason' => str_repeat('Я', 501)]);
            self::fail('The public byte limit must reject 1002 UTF-8 bytes.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            self::assertArrayHasKey('reason', $error->errors());
        }
        self::assertSame(1, EstimateGenerationLearningExample::query()->where('generation_session_id', $session->id)->count());
        self::assertSame(1, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_known_typed_native_sheet_keeps_its_previous_composer_wire_and_fingerprint(): void
    {
        [$session, $actor] = $this->ordinaryWorkbook(typed: true);
        app(InitialProjectFactsWriter::class)->write($session, $actor);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $input = app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null, ['equipment'], 'universal', $session->input_payload]);
        $previous = (new EstimateComposerInputFactory(app(ProjectModelRepository::class), 10000, app(EstimateGenerationLearningEvidenceService::class)))
            ->capture(...[...$scope, [], [], [], null, ['equipment'], 'universal', $session->input_payload]);
        self::assertSame([], $input->nativeCellContext);
        self::assertSame(RunEstimateComposer::PROMPT_CONTRACT, $input->contractVersion);
        self::assertSame($previous->canonicalPayload(), $input->canonicalPayload());
        self::assertSame($previous->fingerprint(), $input->fingerprint());
        $facts = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 100])['snapshot']->facts;
        $native = array_values(array_filter($facts, static fn ($fact): bool => $fact->type === 'equipment_count'));
        self::assertCount(1, $native);
        self::assertNotEmpty($native[0]->nativeNumericProofs);
        Http::assertNothingSent();
    }

    public function test_re_review_after_revoke_keeps_append_only_history_and_uses_the_new_current_literal_even_when_returning_to_the_first_cell(): void
    {
        [$session, $actor, $document] = $this->ordinaryWorkbook(neighbors: true, alternate: true);
        app(InitialProjectFactsWriter::class)->write($session, $actor);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $native = array_values(array_filter($models->snapshotForPlanning(...[...$scope, 100])['snapshot']->facts,
            static fn ($fact): bool => $fact->type === 'equipment_count'))[0];
        $history = [];
        $entity = null;
        foreach (['B2' => 'Готовое выставочное оборудование.', 'E2' => 'Дополнительное исполнение.', 'B2:again' => 'Готовое выставочное оборудование.'] as $address => $value) {
            if ($history !== []) {
                app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\TransitionEstimateGenerationSession::class)
                    ->handle($session->fresh(), (int) $session->fresh()->state_version,
                        \App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent::InputConfirmed, $actor);
            }
            $this->composeCells($session->fresh(), $actor, publish: true, valueCellAddress: explode(':', $address)[0]);
            $reviews = app(ReviewSpreadsheetCellInterpretation::class);
            $session = $session->fresh();
            $rows = $reviews->rows($actor, $session, (int) $document->id);
            self::assertCount(1, $rows);
            self::assertSame($value, $rows[0]['value_cell']['raw_value']);
            $result = $reviews->handle($actor, $scope[1], $scope[2], (int) $document->id, $this->reviewRequest($session, $rows[0]));
            $capture = app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null, ['equipment'], 'universal', $session->fresh()->input_payload]);
            self::assertCount(1, $capture->sourceClaimReferences);
            $reference = $capture->sourceClaimReferences[0];
            self::assertSame($value, $reference['value']['data']);
            self::assertSame($result['decision_id'], $reference['source_review_decision_id']);
            $current = $models->fact(...[...$scope, $reference['document_fact_ids'][0]]);
            self::assertSame(count($history) + 1, $current->version);
            self::assertSame($history === [] ? null : array_key_last($history), $current->supersedesFactId);
            self::assertFalse(isset($history[$current->id]));
            $entity ??= $current->entityId;
            self::assertSame($entity, $current->entityId);
            foreach ($history as $id => $old) {
                self::assertSame($old, get_object_vars($models->fact(...[...$scope, $id])));
            }
            $history[$current->id] = get_object_vars($current);
            $revoke = $this->reviewRequest($session->fresh(), $rows[0], 'revoke');
            $reviews->handle($actor, $scope[1], $scope[2], (int) $document->id, $revoke);
            self::assertSame([], app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null, ['equipment'], 'universal', $session->fresh()->input_payload])->sourceClaimReferences);
            $currentFacts = array_column($models->snapshotForPlanning(...[...$scope, 100])['snapshot']->facts, null, 'id');
            self::assertSame(get_object_vars($native), get_object_vars($currentFacts[$native->id]));
        }
        self::assertSame(3, EstimateGenerationLearningExample::query()->where('generation_session_id', $session->id)->count());
        self::assertSame(3, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_reject_does_not_hide_the_raw_row_or_create_learning_authority(): void
    {
        [$session, $actor, $document] = $this->ordinaryWorkbook();
        app(InitialProjectFactsWriter::class)->write($session, $actor);
        $this->composeCells($session, $actor, publish: true);
        $session = $session->fresh();
        $reviews = app(ReviewSpreadsheetCellInterpretation::class);
        $row = $reviews->rows($actor, $session, (int) $document->id)[0];
        $reviews->handle($actor, (int) $session->project_id, (int) $session->id, (int) $document->id, $this->reviewRequest($session, $row, 'reject'));
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $capture = app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null, ['equipment'], 'universal', $session->fresh()->input_payload]);
        self::assertSame([], $capture->sourceClaimReferences);
        self::assertContains($row['identity_cell']['cell_id'], array_column($capture->nativeCellContext[1]['cells'], 'cell_id'));
        self::assertSame(0, EstimateGenerationLearningExample::query()->where('generation_session_id', $session->id)->count());
        self::assertSame(1, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_a_different_reviewed_identity_cell_creates_a_new_document_local_entity_and_preserves_the_revoked_identity(): void
    {
        [$session, $actor, $document] = $this->ordinaryWorkbook(neighbors: true, alternate: true);
        app(InitialProjectFactsWriter::class)->write($session, $actor);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $this->composeCells($session, $actor, publish: true);
        $reviews = app(ReviewSpreadsheetCellInterpretation::class);
        $models = app(ProjectModelRepository::class);
        $session = $session->fresh();
        $row = $reviews->rows($actor, $session, (int) $document->id)[0];
        $reviews->handle($actor, $scope[1], $scope[2], (int) $document->id, $this->reviewRequest($session, $row));
        $before = app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null, ['equipment'], 'universal', $session->fresh()->input_payload]);
        $old = $before->sourceClaimReferences[0];
        $oldFact = get_object_vars($models->fact(...[...$scope, $old['document_fact_ids'][0]]));
        $oldEntity = DB::table('estimate_generation_project_model_entities')->where('session_id', $scope[2])->where('stable_key', $old['entity_id'])->sole();
        $reviewPageId = DB::table('estimate_generation_document_pages')->where('processing_unit_id', $row['unit_id'])->sole()->id;
        $neighborViews = DB::table('estimate_generation_document_facts')->where('document_id', $document->id)->where('page_id', '<>', $reviewPageId)->orderBy('id')->get()->map(fn ($record) => (array) $record)->all();
        self::assertNotEmpty($neighborViews);
        $reviews->handle($actor, $scope[1], $scope[2], (int) $document->id, $this->reviewRequest($session->fresh(), $row, 'revoke'));
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\TransitionEstimateGenerationSession::class)
            ->handle($session->fresh(), (int) $session->fresh()->state_version,
                \App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent::InputConfirmed, $actor);
        $this->composeCells($session->fresh(), $actor, publish: true, identityCellAddress: 'F2', forbiddenFactIds: [$old['document_fact_ids'][0]]);
        $session = $session->fresh();
        $new = $reviews->rows($actor, $session, (int) $document->id)[0];
        self::assertSame('QA-U1 alternate', $new['identity_cell']['raw_value']);
        $reviews->handle($actor, $scope[1], $scope[2], (int) $document->id, $this->reviewRequest($session, $new));
        $after = app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null, ['equipment'], 'universal', $session->fresh()->input_payload]);
        self::assertCount(1, $after->sourceClaimReferences);
        self::assertNotSame($old['entity_id'], $after->sourceClaimReferences[0]['entity_id']);
        self::assertSame($old['value'], $after->sourceClaimReferences[0]['value']);
        self::assertNotContains($old['document_fact_ids'][0], array_column($after->canonicalPayload()['facts'], 'id'));
        $classifier = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\WorkIntentSourceClassifier;
        $intent = ['work_key' => 'equipment.install', 'work_section' => 'equipment', 'entity_id' => $old['entity_id'], 'source_fact_ids' => $old['document_fact_ids']];
        try {
            $classifier->binding($intent, $after->facts);
            self::fail('A revoked semantic binding cannot remain an eligible work source.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('estimate_composer_work_source_unrelated', $error->getMessage());
        }
        $currentReference = $after->sourceClaimReferences[0];
        $binding = $classifier->binding([...$intent, 'entity_id' => $currentReference['entity_id'], 'source_fact_ids' => $currentReference['document_fact_ids']], $after->facts);
        self::assertSame($currentReference['document_fact_ids'], $binding['basis_fact_ids']);
        self::assertSame($oldFact, get_object_vars($models->fact(...[...$scope, $old['document_fact_ids'][0]])));
        self::assertEquals($oldEntity, DB::table('estimate_generation_project_model_entities')->where('id', $oldEntity->id)->sole());
        self::assertSame($neighborViews, DB::table('estimate_generation_document_facts')->whereIn('id', array_column($neighborViews, 'id'))->orderBy('id')->get()->map(fn ($record) => (array) $record)->all());
    }

    public function test_two_rows_allow_free_sequential_reviews_and_keep_their_original_anchors_after_the_next_generation(): void
    {
        [$session, $actor, $document] = $this->ordinaryWorkbook(multiple: true);
        app(InitialProjectFactsWriter::class)->write($session, $actor);
        [$firstOperation] = $this->composeCells($session, $actor, publish: true);
        $reviews = app(ReviewSpreadsheetCellInterpretation::class);
        $session = $session->fresh();
        $rows = $reviews->rows($actor, $session, (int) $document->id);
        self::assertCount(2, $rows);
        $accepted = [];
        foreach ($rows as $row) {
            $session = $session->fresh();
            $accepted[] = $reviews->handle($actor, (int) $session->project_id, (int) $session->id, (int) $document->id,
                $this->reviewRequest($session, $row));
        }
        self::assertSame(1, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame(2, EstimateGenerationLearningExample::query()->where('generation_session_id', $session->id)->count());
        $session = $session->fresh();
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\TransitionEstimateGenerationSession::class)
            ->handle($session, (int) $session->state_version,
                \App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent::InputConfirmed, $actor);
        [$secondOperation] = $this->composeCells($session->fresh(), $actor, publish: true, expectedRefs: 2);
        self::assertNotSame($firstOperation, $secondOperation);
        $session = $session->fresh();
        $current = $reviews->rows($actor, $session, (int) $document->id);
        self::assertCount(2, $current);
        self::assertSame(['accept', 'accept'], array_column($current, 'status'));
        self::assertSame(array_column($rows, 'proposal_run_id'), array_column($current, 'proposal_run_id'));
        self::assertSame(array_column($rows, 'proposal_hash'), array_column($current, 'proposal_hash'));
        $before = DB::table('estimate_generation_document_facts')->where('document_id', $document->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        self::assertCount(2, $before);
        $request = $this->reviewRequest($session, $current[0], 'revoke');
        $reviews->handle($actor, (int) $session->project_id, (int) $session->id, (int) $document->id, $request);
        $remaining = DB::table('estimate_generation_document_facts')->where('document_id', $document->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        self::assertSame([$before[1]], $remaining);
        $session = $session->fresh();
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $next = app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null, ['equipment'], 'universal', $session->input_payload]);
        self::assertCount(1, $next->sourceClaimReferences);
        self::assertSame($accepted[1]['decision_id'], $next->sourceClaimReferences[0]['source_review_decision_id']);
        self::assertSame([1, 2], array_column($next->nativeCellContext, 'row'));
        self::assertSame(2, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_explicit_review_publishes_a_document_local_literal_and_current_learning_without_numeric_authority(): void
    {
        [$session, $actor, $document] = $this->ordinaryWorkbook();
        app(InitialProjectFactsWriter::class)->write($session, $actor);
        [$operationId] = $this->composeCells($session, $actor, publish: true);
        $session = $session->fresh();
        self::assertSame('input_review_required', $session->status->value);
        self::assertSame($operationId, $session->analysis_payload['credit_operation_receipt']['operation_id']);
        $reviews = app(ReviewSpreadsheetCellInterpretation::class);
        $rows = $reviews->rows($actor, $session, (int) $document->id);
        self::assertCount(1, $rows);
        self::assertSame('pending', $rows[0]['status']);
        $row = $rows[0];
        $request = ['request_id' => (string) Str::uuid(), 'state_version' => (int) $session->state_version,
            'source_version' => $row['source_version'], 'unit_id' => $row['unit_id'], 'proposal_run_id' => $row['proposal_run_id'],
            'proposal_hash' => $row['proposal_hash'], 'decision' => 'accept', 'reason' => 'Подтверждаю отдельное изделие и смысл исходной ячейки.',
            'identity_confirmation' => 'new_document_local_equipment'];
        $beforeUsage = DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count();
        \Tests\Support\AssistantRagTestSchema::create();
        $indexing = app(\App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState::class);
        $indexing->endMigration();
        self::assertFalse($indexing->paused());
        $result = $reviews->handle($actor, (int) $session->project_id, (int) $session->id, (int) $document->id, $request);
        self::assertSame(CanonicalPipelineJson::encode($result), CanonicalPipelineJson::encode($reviews->handle($actor,
            (int) $session->project_id, (int) $session->id, (int) $document->id, $request)));
        self::assertSame($beforeUsage, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame('unresolved', $result['engineering_applicability']);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $capture = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 100]);
        $documents = array_values(array_filter($capture['snapshot']->facts, static fn ($fact): bool => $fact->origin === 'document'));
        self::assertCount(1, $documents);
        self::assertSame('equipment_specification', $documents[0]->type);
        self::assertSame('Готовое выставочное оборудование.', $documents[0]->value);
        self::assertNull($documents[0]->unit);
        self::assertSame([], $documents[0]->nativeNumericProofs);
        $ledger = array_values(array_filter($capture['snapshot']->facts, static fn ($fact): bool => ($fact->value['kind'] ?? null) === 'document_cell_review'));
        self::assertCount(1, $ledger);
        self::assertSame('user_input', $ledger[0]->origin);
        self::assertSame($result['decision_id'], $ledger[0]->value['decision_id']);
        $learning = EstimateGenerationLearningExample::query()->where('generation_session_id', $session->id)->sole();
        self::assertSame('composer_source_claim', $learning->source_type);
        self::assertFalse($learning->is_positive);
        self::assertNull($learning->norm_code);
        self::assertNull($learning->accepted_at);
        $session = $session->fresh();
        $next = app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null, ['equipment'], 'universal', $session->input_payload]);
        self::assertCount(1, $next->sourceClaimReferences);
        self::assertSame((int) $learning->id, $next->sourceClaimReferences[0]['learning_example_id']);
        self::assertSame($documents[0]->entityId, $next->sourceClaimReferences[0]['entity_id']);
        self::assertSame([$documents[0]->id], $next->sourceClaimReferences[0]['document_fact_ids']);
        self::assertSame($result['decision_id'], $next->sourceClaimReferences[0]['source_review_decision_id']);
        self::assertSame([], $next->derivedQuantities);
        self::assertSame([], $next->candidates);
        $runs = \App\BusinessModules\Features\AIAssistant\Models\RagIndexRun::query()->where('organization_id', $scope[0])->get();
        self::assertSame(0, \App\BusinessModules\Features\AIAssistant\Models\RagIndexRun::query()->where('organization_id', $scope[0])
            ->where('source_type', 'estimate_generation_learning')->where('entity_type', 'estimate_generation_learning_example')->count());
        self::assertCount(1, $runs);
        self::assertSame('organization_reporting', $runs[0]->source_type);
        self::assertSame('estimate_generation_session_card', $runs[0]->entity_type);
        self::assertSame((string) $session->id, $runs[0]->entity_id);
        self::assertSame($scope[1], $runs[0]->project_id);
        Queue::assertPushed(\App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob::class, 1);
        Queue::assertPushed(\App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob::class,
            static fn ($job): bool => $job->organizationId === $scope[0] && $job->projectId === $scope[1]
                && $job->entityType === 'estimate_generation_session_card' && (string) $job->entityId === (string) $scope[2]);
        $chunks = iterator_to_array((function () use ($scope): \Generator {
            yield from app(\App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\OrganizationReportingRagSource::class)
                ->collectEntity($scope[0], 'estimate_generation_session_card', $scope[2]);
        })());
        self::assertCount(1, $chunks);
        self::assertSame($result['state_version'], $chunks[0]->metadata['state_version']);
        self::assertStringNotContainsString('Готовое выставочное оборудование.', $chunks[0]->content);
        self::assertStringNotContainsString('document_cell_review', $chunks[0]->content);
        self::assertArrayNotHasKey('input_payload', $chunks[0]->metadata);
        Http::assertNothingSent();
    }

    public function test_paid_composer_persists_only_bounded_cell_proposals_and_replays_without_another_wire(): void
    {
        [$session, $actor] = $this->ordinaryWorkbook();
        app(InitialProjectFactsWriter::class)->write($session, $actor);
        [$operationId, $runId, $proposal, $input] = $this->composeCells($session, $actor);
        $rows = app(EstimateGenerationLearningRecorder::class)->cellReviewProposalsForOperation($session->fresh(), $operationId);
        self::assertCount(1, $rows);
        self::assertSame($runId, $rows[0]['run_id']);
        self::assertEquals($proposal, $rows[0]['proposal']);
        self::assertCount(4, $rows[0]['proposal']);
        self::assertEquals($input->nativeCellContext[1], $rows[0]['binding']);
        self::assertCount(1, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->get());
        $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning((int) $session->organization_id, (int) $session->project_id, (int) $session->id, 100)['snapshot'];
        self::assertSame([], array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->origin === 'document')));
    }

    public function test_ordinary_binary_sheet_reaches_the_existing_composer_as_literal_context_without_creating_facts(): void
    {
        [$session, $actor] = $this->ordinaryWorkbook();
        app(InitialProjectFactsWriter::class)->write($session, $actor);
        $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning((int) $session->organization_id, (int) $session->project_id, (int) $session->id, 100)['snapshot'];
        self::assertSame([], array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->origin === 'document')));
        $input = app(EstimateComposerInputFactory::class)->capture((int) $session->organization_id, (int) $session->project_id,
            (int) $session->id, [], [], [], allowedWorkSections: ['equipment'], evaluationMode: 'universal');
        $payload = $input->canonicalPayload();
        self::assertArrayHasKey('native_cell_context', $payload);
        self::assertNotEmpty($payload['native_cell_context']);
        self::assertContains('Готовое выставочное оборудование.', array_column($payload['native_cell_context'][1]['cells'], 'raw_value'));
        Http::assertNothingSent();
    }

    public function test_cell_context_scans_past_typed_units_and_caps_eligible_rows_without_another_composer_wire(): void
    {
        [$session, $actor, $document] = $this->ordinaryWorkbook(leadingTypedSheets: 8, extraLiteralRows: 8);
        app(InitialProjectFactsWriter::class)->write($session, $actor);
        $units = EstimateGenerationProcessingUnit::query()->where('organization_id', $session->organization_id)
            ->where('project_id', $session->project_id)->where('session_id', $session->id)->where('document_id', $document->id)->orderBy('id')->get();
        self::assertCount(9, $units);
        $input = app(EstimateComposerInputFactory::class)->capture((int) $session->organization_id, (int) $session->project_id,
            (int) $session->id, [], [], [], allowedWorkSections: ['equipment'], evaluationMode: 'universal');
        self::assertCount(8, $input->nativeCellContext);
        self::assertSame(['Перечень'], array_values(array_unique(array_column($input->nativeCellContext, 'sheet'))));
        self::assertSame([(int) $units[8]->id], array_values(array_unique(array_column($input->nativeCellContext, 'unit_id'))));
        self::assertSame([$document->source_version], array_values(array_unique(array_column($input->nativeCellContext, 'source_version'))));
        foreach ($input->nativeCellContext as $row) {
            self::assertEquals($row, SpreadsheetCellReviewSource::wire([
                app(SpreadsheetCellReviewSource::class)
                    ->resolve($session, (int) $document->id, $row['unit_id'], $row['row_id']),
            ])[0]);
        }
        [$operationId] = $this->composeCells($session, $actor);
        self::assertCount(7, app(EstimateGenerationLearningRecorder::class)->cellReviewProposalsForOperation($session->fresh(), $operationId));
        self::assertSame(1, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    private function ordinaryWorkbook(bool $multiple = false, bool $typed = false, bool $neighbors = false, bool $alternate = false, int $leadingTypedSheets = 0, int $extraLiteralRows = 0): array
    {
        Http::fake([]);
        Http::preventStrayRequests();
        Queue::fake();
        $ocr = \Mockery::mock(OcrClientInterface::class);
        $ocr->shouldNotReceive('recognize');
        $this->app->instance(OcrClientInterface::class, $ocr);
        $spreadsheet = new Spreadsheet;
        $data = [
            ['Идентификатор изделия', 'Наименование', 'Количество', 'Единица'],
            ['QA-U1', 'Готовое выставочное оборудование.', 3, 'шт'],
            ...($multiple ? [['QA-U2', 'Отдельная витрина.', 2, 'шт']] : []),
        ];
        for ($index = 0; $index < $extraLiteralRows; $index++) {
            $data[] = ['QA-U'.($index + 3), 'Отдельное выставочное изделие '.($index + 3).'.', 1, 'шт'];
        }
        if ($typed) {
            $data = [['entity_key', 'parameter', 'value', 'unit'],
                ['equipment:known', 'equipment_specification', 'Готовое оборудование.', ''],
                ['equipment:known', 'equipment_count', 3, 'count']];
        }
        if ($alternate) {
            $data[0][] = 'Исполнение';
            $data[1][] = 'Дополнительное исполнение.';
            $data[0][] = 'Альтернативное обозначение';
            $data[1][] = 'QA-U1 alternate';
        }
        $spreadsheet->getActiveSheet()->setTitle('Перечень')->fromArray($data);
        for ($index = 0; $index < $leadingTypedSheets; $index++) {
            $spreadsheet->createSheet($index)->setTitle('Исходные данные '.($index + 1))->fromArray([
                ['entity_key', 'parameter', 'value', 'unit'],
                ['equipment:known'.($index + 1), 'equipment_specification', 'Готовое оборудование.', ''],
                ['equipment:known'.($index + 1), 'equipment_count', 3, 'count'],
            ]);
        }
        if ($neighbors) {
            $spreadsheet->createSheet()->setTitle('Подтвержденные данные')->fromArray([
                ['entity_key', 'parameter', 'value', 'unit'], ['equipment:neighbor', 'equipment_count', 4, 'count'],
            ]);
        }
        $path = tempnam(sys_get_temp_dir(), 'most-cell-review-');
        try {
            (new Xlsx($spreadsheet))->save($path);
            $organization = Organization::factory()->create();
            $project = Project::factory()->for($organization)->createQuietly();
            $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
            $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
            $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
                'user_id' => $actor->id, 'status' => 'draft', 'processing_stage' => 'draft', 'processing_progress' => 0,
                'state_version' => 0, 'input_payload' => ['evaluation_mode' => 'universal', 'profile_id' => 'construction',
                    'selected_sections' => ['equipment'], 'description' => 'Требуется определить работы для указанного оборудования.']]);
            $version = 'sha256:'.hash_file('sha256', $path);
            $document = EstimateGenerationDocument::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
                'session_id' => $session->id, 'user_id' => $actor->id, 'filename' => 'ordinary.xlsx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'storage_path' => 'org-'.$organization->id.'/estimate-generation/cell-review/source.xlsx',
                'file_size_bytes' => filesize($path), 'checksum_sha256' => substr($version, 7), 'source_version' => $version]);
            $storage = new class($path) implements DocumentSourceManifestStorage, DocumentUnitContentReader
            {
                private array $artifacts = [];

                public function __construct(private readonly string $path) {}

                public function open(EstimateGenerationDocument $document, string $sourceVersion): SeekableDocumentSource
                {
                    if ($sourceVersion !== 'sha256:'.hash_file('sha256', $this->path)) {
                        throw new \LogicException('fixture_source_changed');
                    }

                    return new SeekableDocumentSource(fopen($this->path, 'rb'), filesize($this->path));
                }

                public function put(EstimateGenerationDocument $document, string $sourceVersion, DocumentUnitType $type, int $index, string $content, string $contentType = 'text/plain'): StoredDocumentArtifact
                {
                    $hash = 'sha256:'.hash('sha256', $content);
                    $key = 'org-'.$document->organization_id.'/estimate-generation/cell-review/'.substr($hash, 7);
                    $this->artifacts[$key] = $content;

                    return new StoredDocumentArtifact($key, strlen($content), $hash, $contentType);
                }

                public function read(DocumentUnitExecutionContext $context): string
                {
                    $content = $this->artifacts[$context->locator['artifact_path']];
                    if (! str_starts_with($context->locator['artifact_path'], 'org-'.$context->organizationId.'/')
                        || 'sha256:'.hash('sha256', $content) !== $context->locator['artifact_sha256']
                        || strlen($content) !== $context->locator['artifact_bytes']) {
                        throw new \LogicException('fixture_artifact_changed');
                    }

                    return $content;
                }
            };
            $this->app->instance(DocumentSourceManifestStorage::class, $storage);
            $this->app->instance(DocumentUnitContentReader::class, $storage);
            $settings = ['schema_version' => 2, 'models' => ['vision' => 'openai/gpt-6-luna', 'classification' => 'openai/gpt-6-luna', 'normative_matching' => 'openai/gpt-6-luna'],
                'limits' => ['max_files' => 10, 'max_pages_per_file' => 200, 'max_total_pages' => 1000],
                'timeouts' => ['vision' => 60, 'classification' => 60, 'normative_matching' => 60],
                'retries' => ['vision' => 1, 'classification' => 1, 'normative_matching' => 1],
                'confidence' => ['classification' => '0.7000', 'geometry' => '0.7000', 'normative_matching' => '0.7000'],
                'enabled_formats' => ['xlsx'], 'manual_review' => ['low_confidence' => true],
                'budgets' => ['daily' => '100.00', 'monthly' => '1000.00', 'currency' => 'RUB']];
            (new DocumentFileAdmission)->stored($document, EffectiveEstimateGenerationSettings::fromRecord(['snapshot_id' => 1,
                'scope' => 'organization', 'organization_id' => $organization->id, 'version' => 1,
                'snapshot_hash' => SettingsSnapshotHash::calculate($settings), 'snapshot' => $settings], (int) $organization->id));
            $descriptors = app(DocumentUnitDetector::class)->detect($document, $version);
            self::assertCount(($neighbors ? 2 : 1) + $leadingTypedSheets, $descriptors);
            $units = [];
            foreach ($descriptors as $descriptor) {
                $current = app(DocumentProcessingUnitStore::class)->create((int) $organization->id, (int) $project->id, (int) $session->id, (int) $document->id, $descriptor);
                app(ProcessDocumentUnit::class)->handle($current->id, $version);
                self::assertSame('completed', app(DocumentProcessingUnitStore::class)->find($current->id)->status->value);
                $units[] = $current;
            }
            $unit = $units[0];

            return [$session->fresh(), $actor, $document->fresh(), $unit, $storage];
        } finally {
            $spreadsheet->disconnectWorksheets();
            unlink($path);
        }
    }

    private function composeCells(EstimateGenerationSession $session, User $actor, bool $publish = false, ?int $expectedRefs = null, ?string $valueCellAddress = null, ?string $identityCellAddress = null, array $forbiddenFactIds = []): array
    {
        $entitlements = \Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $entitlements->allows('hasModuleAccess')->andReturn(true);
        $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class, $entitlements);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $permissions->allows('can')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        config(['ai-assistant-credits.enforce' => false, 'estimate-generation.credits.enabled' => true, 'estimate-generation.live_qa.enabled' => false]);
        app(AICreditService::class)->grant($session->organization, 1000000, 'purchase', null, 'test:cell-review:'.$session->id);
        $operationId = (string) Str::uuid();
        $operations = app(EstimatorCreditOperations::class);
        $quote = $operations->quote($actor, (int) $session->project_id, (int) $session->id, (int) $session->state_version, $operationId, 'generation');
        DB::transaction(fn () => $operations->begin($actor, $session, (int) $session->state_version, $operationId, $quote['quote_id'], 'generation'));
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $input = app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [],
            new AiSessionWireScope((int) $session->state_version, $operationId, $operationId), ['equipment'], 'universal', $session->input_payload]);
        $row = array_values(array_filter($input->nativeCellContext, static fn (array $row): bool => $row['row'] > 1))[0] ?? $input->nativeCellContext[0];
        $proposal = ['row_id' => $row['row_id'], 'identity_cell_id' => $row['cells'][0]['cell_id'],
            'value_cell_id' => $row['cells'][1]['cell_id'], 'fact_type' => 'equipment_specification'];
        $proposals = array_map(static fn (array $row): array => ['row_id' => $row['row_id'],
            'identity_cell_id' => $identityCellAddress === null ? $row['cells'][0]['cell_id'] : array_column($row['cells'], null, 'address')[$identityCellAddress]['cell_id'],
            'value_cell_id' => $valueCellAddress === null ? $row['cells'][1]['cell_id'] : array_column($row['cells'], null, 'address')[$valueCellAddress]['cell_id'],
            'fact_type' => 'equipment_specification'],
            array_values(array_filter($input->nativeCellContext, static fn (array $row): bool => $row['row'] > 1)));
        $wire = $this->createMock(RerankWireClient::class);
        $wire->method('provider')->willReturn('timeweb');
        $wire->expects(self::once())->method('call')->willReturnCallback(function (string $model, array $messages, array $options) use ($input, $proposals, $scope, $operationId, $expectedRefs, $forbiddenFactIds): array {
            self::assertSame($input->canonicalPayload(), json_decode($messages[1]['content'], true, 512, JSON_THROW_ON_ERROR)['project']);
            foreach ($forbiddenFactIds as $id) {
                self::assertNotContains($id, array_column(json_decode($messages[1]['content'], true, 512, JSON_THROW_ON_ERROR)['project']['facts'], 'id'));
            }
            self::assertStringContainsString('cell_mapping_proposals', $messages[0]['content']);
            if ($expectedRefs !== null) {
                $sent = json_decode($messages[1]['content'], true, 512, JSON_THROW_ON_ERROR)['project'];
                self::assertCount($expectedRefs, $sent['source_claim_refs']);
                self::assertSame([], array_values(array_filter($sent['native_cell_context'], static fn (array $row): bool => $row['row'] > 1)));
                self::assertStringContainsString('document_cell_review', $messages[0]['content']);
                foreach ($sent['source_claim_refs'] as $reference) {
                    self::assertCount(6, $reference);
                    self::assertArrayNotHasKey('source_review_decision_id', $reference);
                    self::assertSame('equipment_specification', $reference['fact_type']);
                    self::assertNull($reference['unit']);
                }
            }
            $attempt = $options['estimate_generation_attempt']['attempt_id'];
            DB::transaction(static function () use ($operationId, $attempt, $scope): void {
                app(EstimatorCreditAttemptAdmission::class)->admit(...[$operationId, $attempt, ...$scope,
                    'estimate_composition', new AiCost('1.00000000', 'RUB', 'available')]);
                DB::table('estimate_generation_vision_physical_attempts')->where('attempt_id', $attempt)
                    ->where('state', 'pre_wire')->update(['state' => 'wire_started', 'wire_started_at' => now()]);
            });

            return ['content' => json_encode(['work_intents' => [], 'cell_mapping_proposals' => $proposals], JSON_THROW_ON_ERROR),
                'model' => $model, 'usage_available' => true, 'input_tokens' => 1000, 'output_tokens' => 100];
        });
        $composer = new RunEstimateComposer(app(AiRoleRunRepository::class),
            new TimewebEstimateComposerModel($wire, app(EloquentAiUsageStore::class), app(AiPriceSnapshotResolver::class),
                'openai/gpt-6-luna', 524288, 8192, 60), 'openai/gpt-6-luna', allowance: app(EstimateComposerAllowance::class));
        if ($publish) {
            $this->publishQuestion($actor, $session, $operationId, $composer);
        } else {
            $outcome = $composer->runDetailed($input);
            self::assertSame([], $outcome['intents']);
            self::assertSame(['estimate_composer_work_scope_unresolved'], array_column($outcome['quarantined'], 'reason'));
            self::assertSame($outcome, $composer->runDetailed($input));
        }
        $runId = (int) DB::table('estimate_generation_ai_role_runs')->where('session_id', $session->id)
            ->whereIn('physical_attempt_id', DB::table('estimate_generation_vision_physical_attempts')
                ->where('credit_operation_id', $operationId)->where('credit_role', 'estimate_composition')->select('attempt_id'))->sole()->id;
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->sole();
        if (! $publish) {
            DB::transaction(fn () => app(EstimatorCreditOperationSettlement::class)->publish($session, $operationId,
                ['source_input_version' => $operation->source_version, 'local_estimates' => []]));
        }

        return [$operationId, $runId, $proposal, $input];
    }

    private function reviewRequest(EstimateGenerationSession $session, array $row, string $decision = 'accept'): array
    {
        return ['request_id' => (string) Str::uuid(), 'state_version' => (int) $session->state_version,
            'source_version' => $row['source_version'], 'unit_id' => $row['unit_id'], 'proposal_run_id' => $row['proposal_run_id'],
            'proposal_hash' => $row['proposal_hash'], 'decision' => $decision, 'reason' => 'Подтверждаю решение по указанному отдельному изделию.',
            ...($decision === 'accept' ? ['identity_confirmation' => 'new_document_local_equipment'] : [])];
    }

    private function postReview(User $actor, EstimateGenerationSession $session, EstimateGenerationDocument $document, array $input): \Illuminate\Http\JsonResponse
    {
        $request = \App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ReviewSpreadsheetCellRequest::create('/', 'POST', $input);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));
        $request->setUserResolver(static fn (): User => $actor);
        $request->validateResolved();

        return app(\App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationDocumentController::class)
            ->reviewCell($request, $session->project, $session, $document, app(ReviewSpreadsheetCellInterpretation::class));
    }

    private function publishQuestion(User $actor, EstimateGenerationSession $session, string $operationId, RunEstimateComposer $composer): void
    {
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->sole();
        $session->forceFill(['status' => 'generating', 'input_payload' => [...$session->input_payload,
            'generation_actor_type' => 'user', 'generation_actor_id' => $actor->id, 'generation_attempt_id' => $operationId,
            'generation_credit_operation_id' => $operationId, 'generation_evaluation_input_hash' => $operation->input_hash]])->save();
        $results = new Pipeline\Stages\StageResultFactory(new Pipeline\InMemoryPipelineArtifactStore, Pipeline\PipelineDefinitionGraph::standard());
        $analysis = (new \App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser)->parse($session->input_payload, []);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'profile_id' => 'construction', 'price_policy' => 'normative', 'selected_sections' => ['equipment']];
        $payloads = [Pipeline\ProcessingStage::UnderstandDocuments->value => ['base_input_version' => $operation->source_version,
                'documents' => [], 'documents_count' => 0, 'rebuild_section_key' => null],
            Pipeline\ProcessingStage::UnderstandObject->value => ['analysis' => $analysis],
            Pipeline\ProcessingStage::ExtractQuantities->value => ['quantity_learning_hints' => [], 'building_quantities' => [],
                'quantity_coverage_warnings' => [], 'stage6_generation_context' => []]];
        $outputs = [];
        $context = static function (Pipeline\ProcessingStage $stage, array $outputs) use ($session, $operationId, $operation, &$payloads): Pipeline\PipelineContext {
            $versions = [];
            foreach (Pipeline\PipelineDefinitionGraph::standard()->get($stage)->dependencies as $dependency) {
                $versions[$dependency->value] = $outputs[$dependency->value]->version ?? $operation->source_version;
            }
            return new Pipeline\PipelineContext((int) $session->id, (int) $session->organization_id, (int) $session->project_id,
                (int) $session->state_version, $operation->source_version, 'generating',
                priorOutputs: new Pipeline\PipelinePriorOutputs($outputs, $payloads), generationAttemptId: $operationId,
                baseInputVersion: $operation->source_version, stage: $stage, dependencyVersions: $versions, creditOperationId: $operationId);
        };
        foreach ($payloads as $key => $payload) {
            $stage = Pipeline\ProcessingStage::from($key);
            $outputs[$key] = $results->make($context($stage, $outputs), $stage, $payload)->output;
        }
        $planner = new Pipeline\Stages\PlanWorkItemsStage(app(\App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlanCompiler::class),
            $results, app(Pipeline\AcceptedQuantityEvidenceMaterializer::class), $composer, app(EstimateComposerInputFactory::class));
        $planned = $planner->execute($context(Pipeline\ProcessingStage::PlanWorkItems, $outputs));
        $payloads[Pipeline\ProcessingStage::PlanWorkItems->value] = $planned->transientData;
        $outputs[Pipeline\ProcessingStage::PlanWorkItems->value] = $planned->output;
        self::assertSame([], $planned->transientData['local_estimates']);
        self::assertNotEmpty($planned->transientData['estimate_composition']['request_info']);
        $payloads[Pipeline\ProcessingStage::ResolvePrices->value] = ['regional_context' => [], 'supplementary_materials' => [], 'local_estimates' => []];
        $outputs[Pipeline\ProcessingStage::ResolvePrices->value] = $results->make($context(Pipeline\ProcessingStage::ResolvePrices, $outputs),
            Pipeline\ProcessingStage::ResolvePrices, $payloads[Pipeline\ProcessingStage::ResolvePrices->value])->output;
        $built = (new Pipeline\Stages\BuildDraftStage($results, quantityEvidence: app(Pipeline\AcceptedQuantityEvidenceVerifier::class)))
            ->execute($context(Pipeline\ProcessingStage::BuildDraft, $outputs));
        $payloads[Pipeline\ProcessingStage::BuildDraft->value] = $built->transientData;
        $outputs[Pipeline\ProcessingStage::BuildDraft->value] = $built->output;
        $validated = (new Pipeline\Stages\ValidateDraftStage(app(\App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService::class),
            new \App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftReadinessProjector, $results,
            app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle::class),
            app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInputFactory::class), models: app(ProjectModelRepository::class)))
            ->execute($context(Pipeline\ProcessingStage::ValidateDraft, $outputs));
        $claim = Pipeline\CheckpointClaim::acquired($context(Pipeline\ProcessingStage::ValidateDraft, $outputs), Pipeline\ProcessingStage::ValidateDraft, (string) Str::uuid());
        DB::transaction(fn () => app(Pipeline\PublishValidatedDraft::class)->beforeComplete($claim, $validated, new \DateTimeImmutable));
    }
}
