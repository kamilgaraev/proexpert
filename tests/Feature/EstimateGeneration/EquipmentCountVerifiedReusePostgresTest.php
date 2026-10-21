<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitClaimStatus;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceManifestStorage;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitContentReader;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDetector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SeekableDocumentSource;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\StoredDocumentArtifact;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveSessionPricingSource;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\NativeNumericFactProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationReviewController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\EstimateGenerationFeedbackRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\SelectEstimateGenerationNormativeCandidateRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CanonicalWorkItemQuantityBinder;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\EquipmentEstimateScope;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\EquipmentWorkItemExpander;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningEvidenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsData;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsService;
use App\Models\SystemAdmin;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EquipmentCountVerifiedReusePostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    private ?object $nativeStorage = null;

    public function test_real_selection_records_independently_verified_count_and_current_norm_for_bounded_reader_reuse(): void
    {
        [$actor, $session, $catalog, $source, $answer] = $this->fixture();
        $this->select($actor, $session, $catalog);
        $examples = EstimateGenerationLearningExample::query()->where('generation_session_id', $session->id)->get();
        self::assertSame(['user_selection'], $examples->pluck('source_type')->all());
        foreach ($examples as $example) {
            self::assertTrue($example->is_positive);
            $validation = $example->context_payload['knowledge_validation'] ?? [];
            self::assertSame('passed', data_get($validation, 'count_source_check.outcome'), json_encode($validation, JSON_THROW_ON_ERROR));
            self::assertSame('passed', data_get($validation, 'normative_association_check.outcome'), json_encode($validation, JSON_THROW_ON_ERROR));
            self::assertSame($answer->id, data_get($validation, 'claim.answer_fact_id'));
            self::assertSame($source->id, data_get($validation, 'claim.source_count_fact_id'));
            self::assertSame($catalog['norm_id'], data_get($validation, 'normative_association_check.estimate_norm_id'));
        }
        $item = $this->installation($session);
        $before = CanonicalPipelineJson::encode($item);
        $distractor = EstimateNorm::query()->findOrFail($catalog['norm_id'])->replicate();
        $distractor->code = '20-99-999-02';
        $distractor->save();
        $summary = $this->summaries($session, [$catalog['norm_id'], (int) $distractor->id]);
        self::assertGreaterThan(0, $summary[$catalog['norm_id']]['learning_score']);
        self::assertGreaterThan(0, $summary[$catalog['norm_id']]['learning_positive_count']);
        self::assertNotSame([], $summary[$catalog['norm_id']]['learning_sources']);
        self::assertLessThanOrEqual(6, count($summary[$catalog['norm_id']]['learning_sources']));
        self::assertSame(0.0, $summary[$distractor->id]['learning_score']);
        self::assertSame(0, $summary[$distractor->id]['learning_positive_count']);
        self::assertSame($before, CanonicalPipelineJson::encode($this->installation($session)));
        self::assertSame('3', $this->installation($session)['quantity']);
        Http::assertNothingSent();
    }

    public function test_repeating_confirmation_after_public_selection_is_rejected_without_a_new_example(): void
    {
        [$actor, $session, $catalog] = $this->fixture();
        $this->select($actor, $session, $catalog);
        $before = $this->summaries($session, [$catalog['norm_id']]);
        $response = $this->confirmNorm($actor, $session, $catalog);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['work_item_key' => [trans_message('estimate_generation.normative_confirmation_not_required')]],
            $response->getData(true)['errors']);
        self::assertSame(['user_selection'], EstimateGenerationLearningExample::query()->where('generation_session_id', $session->id)->pluck('source_type')->all());
        self::assertSame($before, $this->summaries($session, [$catalog['norm_id']]));
    }

    public function test_a_human_count_mismatch_does_not_verify_learning_or_overwrite_the_native_original(): void
    {
        [$actor, $session, $catalog, $source, $answer] = $this->fixture('4');
        $this->select($actor, $session, $catalog);
        $example = EstimateGenerationLearningExample::query()->where('generation_session_id', $session->id)->sole();
        self::assertSame('failed', data_get($example->context_payload, 'knowledge_validation.count_source_check.outcome'));
        self::assertSame('unresolved', data_get($example->context_payload, 'knowledge_validation.normative_association_check.outcome'));
        $persisted = app(ProjectModelRepository::class)->factsByIds((int) $session->organization_id,
            (int) $session->project_id, (int) $session->id, [$source->id, $answer->id]);
        $byId = array_column($persisted, null, 'id');
        self::assertSame('3', $byId[$source->id]->value);
        self::assertSame('document', $byId[$source->id]->origin);
        self::assertSame($source->nativeNumericProofs, $byId[$source->id]->nativeNumericProofs);
        self::assertNotEmpty($byId[$source->id]->nativeNumericProofs);
        self::assertSame('4', $byId[$answer->id]->value);
        self::assertSame('user_assumption', $byId[$answer->id]->origin);
        self::assertSame([], $byId[$answer->id]->nativeNumericProofs);
        self::assertSame('4', $this->installation($session)['quantity']);
        $this->assertNoInfluence($this->summaries($session, [$catalog['norm_id']])[$catalog['norm_id']]);
    }

    public function test_source_replacement_revokes_old_knowledge_and_never_copies_the_historical_count(): void
    {
        [$actor, $session, $catalog, $source, $answer] = $this->fixture();
        $this->select($actor, $session, $catalog);
        self::assertGreaterThan(0, $this->summaries($session, [$catalog['norm_id']])[$catalog['norm_id']]['learning_score']);
        $document = EstimateGenerationDocument::query()->where('organization_id', $session->organization_id)
            ->where('project_id', $session->project_id)->where('session_id', $session->id)->sole();
        $replacement = $this->publishCount($session, '4', $document);
        self::assertNotSame($source->sourceVersion, $replacement->sourceVersion);
        self::assertNotSame($source->id, $replacement->id);
        $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning((int) $session->organization_id,
            (int) $session->project_id, (int) $session->id, 100)['snapshot'];
        self::assertNotContains($answer->id, array_column($snapshot->facts, 'id'));
        $this->buildDraft($session, $catalog, $replacement);
        $before = CanonicalPipelineJson::encode($this->installation($session));
        $this->assertNoInfluence($this->summaries($session, [$catalog['norm_id']])[$catalog['norm_id']]);
        $current = $this->installation($session);
        self::assertSame($before, CanonicalPipelineJson::encode($current));
        self::assertSame('4', $current['quantity']);
        self::assertSame($replacement->id, $current['quantity_evidence']['formula_inputs']['operands'][0]['fact_id']);
        self::assertSame($replacement->sourceVersion, $current['quantity_evidence']['formula_inputs']['operands'][0]['source_version']);
    }

    public function test_an_authoritative_dataset_version_change_revokes_previously_verified_influence(): void
    {
        [$actor, $session, $catalog] = $this->fixture();
        $this->select($actor, $session, $catalog);
        self::assertGreaterThan(0, $this->summaries($session, [$catalog['norm_id']])[$catalog['norm_id']]['learning_score']);
        $example = EstimateGenerationLearningExample::query()->where('generation_session_id', $session->id)->sole();
        $norm = EstimateNorm::query()->with('collection')->findOrFail($catalog['norm_id']);
        self::assertSame((int) $norm->collection->dataset_version_id, $example->normative_dataset_version_id);
        $replacementVersion = DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fsnb_2022', 'version_key' => 'synthetic-knowledge-replacement-'.Str::ulid(),
            'bucket' => 'test', 'prefix' => 'test', 'status' => 'parsed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $norm->collection->update(['dataset_version_id' => $replacementVersion]);
        self::assertNotSame($example->normative_dataset_version_id, (int) $norm->collection->fresh()->dataset_version_id);
        $this->assertNoInfluence($this->summaries($session, [$catalog['norm_id']])[$catalog['norm_id']]);
        self::assertSame('3', $this->installation($session)['quantity']);
    }

    #[DataProvider('foreignScopes')]
    public function test_verified_knowledge_has_no_influence_in_a_foreign_scope(string $dimension): void
    {
        [$actor, $session, $catalog] = $this->fixture();
        $this->select($actor, $session, $catalog);
        self::assertGreaterThan(0, $this->summaries($session, [$catalog['norm_id']])[$catalog['norm_id']]['learning_score']);
        $context = match ($dimension) {
            'organization' => ['organization_id' => (int) Organization::factory()->create()->id],
            'project' => ['project_id' => (int) Project::factory()->createQuietly(['organization_id' => $session->organization_id])->id],
            'session' => ['session_id' => (int) EstimateGenerationSession::query()->create([
                'organization_id' => $session->organization_id, 'project_id' => $session->project_id, 'user_id' => $actor->id,
                'status' => 'draft', 'state_version' => 0, 'input_payload' => [], 'draft_payload' => [],
            ])->id],
        };
        $this->assertNoInfluence($this->summaries($session, [$catalog['norm_id']], $context)[$catalog['norm_id']]);
    }

    public static function foreignScopes(): iterable
    {
        foreach (['organization', 'project', 'session'] as $dimension) {
            yield $dimension => [$dimension];
        }
    }

    private function assertNoInfluence(array $summary): void
    {
        self::assertSame(0.0, $summary['learning_score']);
        self::assertSame(0, $summary['learning_positive_count']);
        self::assertSame([], $summary['learning_sources']);
    }

    private function select(User $actor, EstimateGenerationSession $session, array $catalog): void
    {
        $request = SelectEstimateGenerationNormativeCandidateRequest::create('/normative/select', 'POST', [
            'state_version' => $session->fresh()->state_version, 'work_item_key' => $this->installation($session)['key'],
            'norm_id' => $catalog['norm_id'], 'selection_source' => 'catalog_search', 'response_scope' => 'review_queue',
        ]);
        $project = $this->prepareRequest($request, $actor, $session);
        $response = app(EstimateGenerationReviewController::class)->select($request, $project, $session->fresh());
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
    }

    private function confirmNorm(User $actor, EstimateGenerationSession $session, array $catalog): \Illuminate\Http\JsonResponse
    {
        $request = EstimateGenerationFeedbackRequest::create('/feedback', 'POST', [
            'request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
            'feedback_type' => 'normative_confirmation', 'work_item_key' => $this->installation($session)['key'],
            'payload' => ['norm_id' => $catalog['norm_id'], 'normative_code' => '20-99-999-01'],
            'comments' => 'Синтетическая проверка текущей нормы и отдельного документального количества.',
        ]);
        $project = $this->prepareRequest($request, $actor, $session);
        $response = app(EstimateGenerationReviewController::class)->feedback($request, $project, $session->fresh());

        return $response;
    }

    private function prepareRequest(FormRequest $request, User $actor, EstimateGenerationSession $session): Project
    {
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setUserResolver(static fn (): User => $actor);
        $project = Project::query()->findOrFail($session->project_id);
        $route = new Route('POST', 'projects/{project}/feedback', []);
        $route->bind($request);
        $route->setParameter('project', $project);
        $request->setRouteResolver(static fn (): Route => $route);
        $request->validateResolved();
        return $project;
    }

    private function summaries(EstimateGenerationSession $session, array $normIds, array $context = []): array
    {
        return app(EstimateGenerationLearningEvidenceService::class)->summarizeForCandidates(
            EstimateNorm::query()->with('collection')->whereIn('id', $normIds)->get(), $this->installation($session),
            [...['organization_id' => (int) $session->organization_id, 'project_id' => (int) $session->project_id,
                'session_id' => (int) $session->id, 'scope_type' => 'engineering', 'object_type' => 'residential'], ...$context]);
    }

    private function reconfirm(User $actor, EstimateGenerationSession $session, Fact $source, string $value): Fact
    {
        $result = app(SaveManualProjectFacts::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            $session->fresh()->state_version, (string) Str::uuid(), ['entities' => [['key' => 'count-answer', 'type' => 'equipment',
                'existing_entity_id' => $source->entityId,
                'parameters' => ['equipment_count' => ['value' => $value, 'unit' => 'count', 'basis' => 'assumption']]]]]);
        $answer = app(ProjectModelRepository::class)->factsByIds((int) $session->organization_id, (int) $session->project_id,
            (int) $session->id, $result['fact_ids'])[0];
        self::assertNotSame($source->id, $answer->id);
        self::assertSame($source->id, $answer->supersedesFactId);
        self::assertSame('user_assumption', $answer->origin);
        self::assertSame([], $answer->nativeNumericProofs);
        self::assertSame($source->sourceVersion, $answer->sourceVersion);
        $original = app(ProjectModelRepository::class)->factsByIds((int) $session->organization_id,
            (int) $session->project_id, (int) $session->id, [$source->id])[0];
        self::assertSame('document', $original->origin);
        self::assertSame($source->value, $original->value);
        self::assertNotEmpty($original->nativeNumericProofs);

        return $answer;
    }
    private function fixture(string $proposed = '3'): array
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        config(['cache.default' => 'array', 'estimate-generation.universal_enabled' => true]);
        if (app(EstimateGenerationSettingsService::class)->currentSnapshot('global', null) === null) {
            app(EstimateGenerationSettingsService::class)->change((int) SystemAdmin::factory()->create()->id,
                EstimateGenerationSettingsData::fromArray([
                    'scope' => 'global', 'organization_id' => null, 'expected_version' => 0,
                    'idempotency_key' => (string) Str::uuid(),
                    'models' => ['vision' => 'openai/gpt-6-luna', 'classification' => 'fixture/classification', 'normative_matching' => 'fixture/normative'],
                    'limits' => ['max_files' => 8, 'max_pages_per_file' => 80, 'max_total_pages' => 800],
                    'timeouts' => ['vision' => 81, 'classification' => 82, 'normative_matching' => 83],
                    'retries' => ['vision' => 0, 'classification' => 1, 'normative_matching' => 2],
                    'confidence' => ['classification' => '0.7100', 'geometry' => '0.7200', 'normative_matching' => '0.7300'],
                    'enabled_formats' => ['xlsx'], 'manual_review' => ['low_confidence' => true],
                    'budgets' => ['daily' => '100.00', 'monthly' => '1000.00', 'currency' => 'RUB'],
                ]));
        }
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->createQuietly();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        foreach (['estimate_generation.review', 'estimate_generation.select_normative'] as $permission) {
            $authorization->allows('can')->with($actor, $permission, [
                'organization_id' => (int) $organization->id, 'project_id' => (int) $project->id,
            ])->andReturn(true);
        }
        $this->app->instance(AuthorizationService::class, $authorization);
        foreach ([['Штука', 'шт', 'piece'], ['Час', 'ч', 'time']] as [$name, $unit, $type]) {
            MeasurementUnit::query()->create(['organization_id' => $organization->id, 'name' => $name, 'short_name' => $unit, 'type' => $type]);
        }
        $catalog = $this->catalog();
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'processing_stage' => 'draft', 'processing_progress' => 0,
            'state_version' => 0, 'input_payload' => ['evaluation_mode' => 'universal',
                'selected_sections' => ['equipment'], 'profile_id' => 'construction', 'construction_type' => 'new_construction',
                'price_policy' => 'normative', 'regional_context' => $catalog['context']]]);
        app(SaveSessionPricingSource::class)->handle($actor, (int) $project->id, (int) $session->id,
            ['request_id' => (string) Str::uuid(), 'state_version' => 0,
                'estimate_regional_price_version_id' => $catalog['context']['estimate_regional_price_version_id'],
                'conditions' => ['as_of_date' => '2026-10-01', 'vat_mode' => 'excluded', 'vat_rate' => '22',
                    'delivery' => 'outside_scope', 'basis' => 'Синтетическая проверка числового пересчёта; доставка вне выбранного состава.']]);
        $source = $this->publishCount($session);
        $answer = $this->reconfirm($actor, $session, $source, $proposed);
        $this->buildDraft($session, $catalog, $answer);

        return [$actor, $session, $catalog, $source, $answer];
    }

    private function buildDraft(EstimateGenerationSession $session, array $catalog, Fact $claim): void
    {
        $preview = app(CurrentProjectDerivedQuantityService::class)->previewInput((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
        $quantity = (new WorkItemQuantityMapper)->mapForEntity('equipment.install', $preview['quantities'], $claim->entityId);
        self::assertNotNull($quantity);
        self::assertSame((string) $claim->value, $quantity->amount);
        $local = ['key' => 'equipment', 'scope_type' => 'engineering', 'title' => 'Оборудование'];
        $section = ['key' => 'equipment', 'title' => 'Оборудование', 'construction_part' => 'equipment'];
        $analysis = ['object' => ['object_type' => 'residential'], 'regional_context' => $catalog['context'],
            'evaluation_policy' => ['mode' => 'universal', 'profile_id' => 'construction', 'selected_sections' => ['equipment']],
            'document_context' => ['canonical_building_quantities' => [$quantity->toArray()]]];
        $items = app(NormativeWorkItemPlannerService::class)->build($local, $section, $analysis, projectReferences: []);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $snapshot = $models->snapshotForPlanning(...[...$scope, 100])['snapshot'];
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        $draft = $this->emptyDraft($session, $catalog);
        $draft['local_estimates'] = [[...$local, 'sections' => [[...$section, 'work_items' => $items]]]];
        $draft = (new EquipmentWorkItemExpander)->expand($draft, $preview['quantities'], 50, EquipmentEstimateScope::select($snapshot, $decisions));
        $items = array_map(fn (array $row): array => (new CanonicalWorkItemQuantityBinder)->bind($row, $preview['quantities']),
            $draft['local_estimates'][0]['sections'][0]['work_items']);
        $draft['local_estimates'][0]['sections'][0]['work_items'] = $items;
        $installation = array_values(array_filter($items, static fn (array $row): bool => ($row['metadata']['requirement_key'] ?? null) === 'installation'));
        self::assertCount(1, $installation);
        $item = $installation[0];
        self::assertSame((string) $claim->value, $quantity->amount);
        self::assertSame((string) $claim->value, $item['quantity']);
        self::assertSame($claim->entityId, $item['metadata']['scope_entity_id']);
        self::assertSame($claim->id, $item['quantity_evidence']['formula_inputs']['operands'][0]['fact_id']);
        self::assertArrayNotHasKey('specialization_scenario', $item);
        self::assertArrayNotHasKey('work_intent', $item);
        $session->refresh()->update(['status' => 'estimate_review_required',
            'draft_payload' => (new BuildMostEstimateDraft)->seal((new UniversalReviewSummaryProjector)->project($draft))]);

    }

    private function emptyDraft(EstimateGenerationSession $session, array $catalog): array
    {
        return ['generation_contract' => UniversalDraftProjector::CONTRACT,
            'source_input_version' => 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session->fresh()),
            'regional_context' => $catalog['context'], 'object_profile' => ['object_type' => 'residential'],
            'evaluation_policy' => ['selected_sections' => ['equipment'], 'price_policy' => 'normative']];
    }

    private function installation(EstimateGenerationSession $session): array
    {
        $rows = $session->fresh()->draft_payload['local_estimates'][0]['sections'][0]['work_items'];
        $installation = array_values(array_filter($rows,
            static fn (array $row): bool => ($row['metadata']['requirement_key'] ?? null) === 'installation'));
        self::assertCount(1, $installation);

        return $installation[0];
    }

    private function publishCount(EstimateGenerationSession $session, string $value = '3', ?EstimateGenerationDocument $document = null): Fact
    {
        $ocr = Mockery::mock(OcrClientInterface::class);
        $ocr->shouldNotReceive('recognize');
        $this->app->instance(OcrClientInterface::class, $ocr);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Оборудование')->fromArray([
            ['entity_key', 'parameter', 'value', 'unit'],
            ['equipment:boiler', 'equipment_specification', 'Электрический котёл отопления массой до 30 кг.', ''],
            ['equipment:boiler', 'equipment_count', $value, 'шт'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'most-count-reuse-');
        self::assertIsString($path);
        try {
            (new Xlsx($spreadsheet))->save($path);
            $version = 'sha256:'.hash_file('sha256', $path);
            if ($document !== null) {
                app(ProjectModelRepository::class)->invalidateSourceVersion((int) $session->organization_id,
                    (int) $session->project_id, (int) $session->id, $document->source_version, $version);
            }
            $document = EstimateGenerationDocument::query()->create([
                'organization_id' => $session->organization_id, 'project_id' => $session->project_id,
                'session_id' => $session->id, 'user_id' => $session->user_id, 'filename' => 'equipment.xlsx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'storage_path' => 'org-'.$session->organization_id.'/estimate-generation/verified-reuse/'.substr($version, 7).'.xlsx',
                'source_version' => $version, 'checksum_sha256' => substr($version, 7),
            ]);
            if ($this->nativeStorage === null) {
                $storage = new class($path) implements DocumentSourceManifestStorage, DocumentUnitContentReader
                {
                    private array $artifacts = [];

                    public function __construct(private string $sourcePath) {}

                    public function useSourcePath(string $sourcePath): void
                    {
                        $this->sourcePath = $sourcePath;
                    }

                    public function open(EstimateGenerationDocument $document, string $sourceVersion): SeekableDocumentSource
                    {
                        if ($sourceVersion !== 'sha256:'.hash_file('sha256', $this->sourcePath)) {
                            throw new \LogicException('Source digest mismatch.');
                        }

                        return new SeekableDocumentSource(fopen($this->sourcePath, 'rb'), filesize($this->sourcePath));
                    }

                    public function put(EstimateGenerationDocument $document, string $sourceVersion, DocumentUnitType $type, int $index, string $content, string $contentType = 'text/plain'): StoredDocumentArtifact
                    {
                        $hash = 'sha256:'.hash('sha256', $content);
                        $key = 'org-'.$document->organization_id.'/estimate-generation/verified-reuse/'.substr($hash, 7);
                        $this->artifacts[$key] = $content;

                        return new StoredDocumentArtifact($key, strlen($content), $hash, $contentType);
                    }

                    public function read(DocumentUnitExecutionContext $context): string
                    {
                        $key = $context->locator['artifact_path'];
                        $content = $this->artifacts[$key];
                        if (! str_starts_with($key, 'org-'.$context->organizationId.'/')
                            || 'sha256:'.hash('sha256', $content) !== $context->locator['artifact_sha256']
                            || strlen($content) !== $context->locator['artifact_bytes']) {
                            throw new \LogicException('Artifact scope or integrity mismatch.');
                        }

                        return $content;
                    }
                };
                $this->nativeStorage = $storage;
                $this->app->instance(DocumentSourceManifestStorage::class, $storage);
                $this->app->instance(DocumentUnitContentReader::class, $storage);
            } else {
                $this->nativeStorage->useSourcePath($path);
            }
            $units = app(DocumentUnitDetector::class)->detect($document, $version);
            self::assertCount(1, $units);
            $unit = app(DocumentProcessingUnitStore::class)->create((int) $session->organization_id, (int) $session->project_id,
                (int) $session->id, (int) $document->id, $units[0]);
            self::assertSame(DocumentProcessingUnitClaimStatus::Acquired, app(ProcessDocumentUnit::class)->handle($unit->id, $version)->status);
            $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning((int) $session->organization_id,
                (int) $session->project_id, (int) $session->id, 100)['snapshot'];
            $counts = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->type === 'equipment_count'));
            self::assertCount(1, $counts);
            $source = $counts[0];
            self::assertSame($value, $source->value);
            self::assertSame('document', $source->origin);
            $proofs = array_values(array_filter($snapshot->evidence, static fn ($evidence): bool => in_array($evidence->id, $source->evidenceIds, true)));
            self::assertCount(1, $proofs);
            self::assertSame('xlsx:sheet:Оборудование!C3', $proofs[0]->nativeReference);
            self::assertTrue((new NativeNumericFactProof)->matches($source, $proofs[0]));

            return $source;
        } finally {
            $spreadsheet->disconnectWorksheets();
            unlink($path);
        }
    }

    private function catalog(): array
    {
        $now = now();
        $suffix = strtolower((string) Str::ulid());
        $key = 'synthetic-scaled-unit2026Q4-'.$suffix;
        $dataset = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fsnb_2022', 'version_key' => $key,
            'bucket' => 'test', 'prefix' => $key, 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $prices = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fgis_labor_prices', 'version_key' => $key.'-prices',
            'bucket' => 'test', 'prefix' => $key.'-prices', 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $collection = DB::table('estimate_norm_collections')->insertGetId(['dataset_version_id' => $dataset, 'code' => 'SYNTHETIC-'.$suffix,
            'name' => 'Synthetic scaled-unit numeric fixture 2026 Q4', 'norm_type' => 'gesn', 'source_file' => 'synthetic-scaled-unit2026Q4', 'created_at' => $now, 'updated_at' => $now]);
        $section = DB::table('estimate_norm_sections')->insertGetId(['collection_id' => $collection, 'code' => '20', 'name' => 'Synthetic equipment',
            'path' => '20/synthetic', 'created_at' => $now, 'updated_at' => $now]);
        $norm = DB::table('estimate_norms')->insertGetId(['collection_id' => $collection, 'code' => '20-99-999-01',
            'section_code' => '20', 'section_name' => 'Synthetic equipment', 'section_id' => $section,
            'name' => 'Монтаж оборудования по требованиям изготовителя',
            'work_composition' => json_encode(['Установка и крепление оборудования по согласованной спецификации'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'unit' => '100 шт', 'valid_from' => '2026-10-01', 'valid_to' => '2026-12-31', 'created_at' => $now, 'updated_at' => $now]);
        $resourceCode = 'R08-LABOR-'.$suffix;
        $resource = DB::table('estimate_norm_resources')->insertGetId(['estimate_norm_id' => $norm,
            'resource_code' => $resourceCode, 'resource_name' => 'Труд рабочих', 'unit' => 'h', 'quantity' => '10.000000',
            'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now]);
        $region = DB::table('estimate_regions')->insertGetId(['code' => 'R08-'.substr($suffix, -10), 'name' => 'Synthetic region',
            'fgiscs_subject_id' => random_int(100000, 999999), 'created_at' => $now, 'updated_at' => $now]);
        $zone = DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $region, 'name' => 'Synthetic zone',
            'fgiscs_price_zone_id' => random_int(1000000, 1999999), 'created_at' => $now, 'updated_at' => $now]);
        $period = DB::table('estimate_price_periods')->where('year', 2026)->where('quarter', 4)->value('id')
            ?? DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => random_int(2000000, 2999999), 'name' => 'Synthetic 2026 Q4',
                'year' => 2026, 'quarter' => 4, 'created_at' => $now, 'updated_at' => $now]);
        $version = DB::table('estimate_regional_price_versions')->insertGetId(['source' => 'fgiscs', 'region_id' => $region,
            'price_zone_id' => $zone, 'period_id' => $period, 'version_key' => $key, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        $price = DB::table('estimate_resource_prices')->insertGetId(['dataset_version_id' => $prices,
            'regional_price_version_id' => $version, 'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period,
            'resource_code' => $resourceCode, 'resource_name' => 'Труд рабочих', 'unit' => 'h', 'base_price' => '100.0000',
            'price_type' => 'labor', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_regional_price_versions')->where('id', $version)->update(['status' => 'active']);

        return ['norm_id' => $norm, 'resource_id' => $resource, 'price_id' => $price, 'context' => ['region_id' => $region,
            'price_zone_id' => $zone, 'period_id' => $period, 'business_date' => '2026-10-01', 'year' => 2026, 'quarter' => 4,
            'version_key' => $key, 'estimate_regional_price_version_id' => $version, 'normative_dataset_version' => $key]];
    }
}
