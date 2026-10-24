<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerAllowance;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\TimewebEstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents as Documents;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\CreateEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetCurrentEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\UserEvaluationChoiceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationQuestionController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\AnswerEstimateClarificationRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline as Pipeline;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ResolveCurrentEstimateClarification;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\ComposerSourceClaimValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveEstimateGenerationSettings;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsData;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsService;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningSourceTrustPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningEvidenceService;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCost;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshotResolver;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EloquentAiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorCreditAttemptAdmission;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Enums\AuthSessionStatus;
use App\Services\Auth\WebAuthTokenService;
use App\Domain\Authorization\Models\AuthorizationContext;
use Tests\Support\AssistantRealAuthorizationFixture;
use App\Services\Credits\AICreditService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class ComposerSourceClaimStorePostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_parameter_learning_storage_shape_and_rollback_preserve_literal_and_component_history(): void
    {
        $literal = $this->row();
        $literalId = DB::table('estimate_generation_learning_examples')->insertGetId($literal);
        $component = [...$literal, 'source_entity_type' => 'estimate_session', 'source_entity_id' => $literal['generation_session_id'],
            'source_claim_key' => str_repeat('b', 64), 'context_payload' => json_encode([
                'origin' => ComposerSourceClaimValidationService::PARAMETER_ORIGIN, 'training_allowed' => false], JSON_THROW_ON_ERROR)];
        $migration = require base_path('app/BusinessModules/Addons/EstimateGeneration/migrations/2026_10_24_000100_allow_work_parameter_component_learning.php');
        try {
            DB::transaction(function () use ($component, $migration): void {
                $id = DB::table('estimate_generation_learning_examples')->insertGetId($component);
                foreach ([
                    ['context_payload' => json_encode([], JSON_THROW_ON_ERROR)],
                    ['context_payload' => json_encode(['origin' => null], JSON_THROW_ON_ERROR)],
                    ['context_payload' => json_encode(['origin' => 'other'], JSON_THROW_ON_ERROR)],
                    ['source_entity_type' => null], ['source_entity_id' => null],
                    ['is_positive' => true], ['accepted_at' => now()], ['norm_code' => 'fake'],
                    ['decision_status' => 'confirmed_by_user'], ['source_claim_key' => null],
                ] as $change) {
                    try {
                        DB::transaction(fn () => DB::table('estimate_generation_learning_examples')->insert([...$component, ...$change]));
                        self::fail('A component escaped the closed nonpositive storage shape.');
                    } catch (QueryException $exception) {
                        self::assertSame('23514', $exception->errorInfo[0]);
                    }
                }
                try {
                    $migration->down();
                    self::fail('Rollback must preserve component history.');
                } catch (\RuntimeException $exception) {
                    self::assertSame('work_parameter_component_learning_requires_preserved_history', $exception->getMessage());
                }
                self::assertTrue(DB::table('estimate_generation_learning_examples')->where('id', $id)->exists());
                DB::table('estimate_generation_sessions')->where('id', $component['generation_session_id'])->delete();
                $retained = DB::table('estimate_generation_learning_examples')->where('id', $id)->sole();
                self::assertNull($retained->generation_session_id);
                self::assertSame($component['source_entity_id'], $retained->source_entity_id);
                self::assertEquals(json_decode($component['context_payload'], true, flags: JSON_THROW_ON_ERROR),
                    json_decode($retained->context_payload, true, flags: JSON_THROW_ON_ERROR));
                throw new \RuntimeException('rollback_component_test_fixture');
            });
        } catch (\RuntimeException $exception) {
            self::assertSame('rollback_component_test_fixture', $exception->getMessage());
        }
        $migration->down();
        try {
            try {
                DB::transaction(fn () => DB::table('estimate_generation_learning_examples')->insert($component));
                self::fail('The restored historical constraint accepted a component.');
            } catch (QueryException $exception) {
                self::assertSame('23514', $exception->errorInfo[0]);
            }
            self::assertTrue(DB::table('estimate_generation_learning_examples')->where('id', $literalId)->exists());
        } finally {
            $migration->up();
        }
    }

    public function test_review_work_answer_survives_authorized_native_upload_without_automatic_generation(): void
    {
        Queue::fake();
        config(['cache.default' => 'array', 'web_auth.origins.admin' => ['https://admin.fixture.test']]);
        $rights = AssistantRealAuthorizationFixture::create(['working-entry']);
        $rights->memberRole->update(['module_permissions' => ['ai-estimates' => ['estimate_generation.create', 'estimate_generation.generate',
            'estimate_generation.view', 'estimate_generation.review', 'estimate_generation.upload_documents']], 'system_permissions' => ['admin.access']]);
        $rights->member->organizations()->updateExistingPivot($rights->organization->id, ['project_access_mode' => 'all_projects']);
        [$actor, $session, $initialAnswer, $sources] = $this->answeredSourceFixture(naturalInitial: true,
            targetMode: 'selected', realActor: $rights->member);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $organization = Organization::query()->findOrFail($scope[0]);
        $project = Project::query()->findOrFail($scope[1]);
        [$session] = $this->paidReceipt(RunEstimateComposer::ANSWER_PROMPT_CONTRACT, [], true,
            [$organization, $project, $actor, $session], false,
            function (EstimateGenerationSession $current, string $operationId) use ($actor, $scope, $initialAnswer, $sources): int {
                $work = ['kind' => 'supplementary', 'candidate_id' => null, 'work_key' => 'custom.installation',
                    'name' => 'Монтаж неизвестной установки', 'work_section' => 'equipment', 'unit' => 'count',
                    'derived_quantity_id' => null, 'source_fact_ids' => [$initialAnswer->id, $sources[0]->id],
                    'entity_id' => $sources[0]->entityId, 'technology_package_candidate' => null,
                    'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => [],
                    'technology_clarification' => ['question' => 'Какой способ монтажа предусмотрен для этой установки?',
                        'reason' => 'В текущем источнике не описан способ монтажа.']];
                $wire = $this->createMock(RerankWireClient::class);
                $wire->method('provider')->willReturn('timeweb');
                $wire->expects(self::once())->method('call')->willReturnCallback(fn (string $model, array $messages, array $options): array =>
                    $this->composerWireResponse($scope, $operationId, $options, ['work_intents' => [$work], 'claim_candidates' => []], $model));
                $composer = new RunEstimateComposer(app(AiRoleRunRepository::class),
                    new TimewebEstimateComposerModel($wire, app(EloquentAiUsageStore::class), app(AiPriceSnapshotResolver::class),
                        'openai/gpt-6-luna', 524288, 8192, 60), 'openai/gpt-6-luna', allowance: app(EstimateComposerAllowance::class));
                [$claim, $result] = $this->naturalQuestionStages($actor, $current, $operationId, $composer, true);
                DB::transaction(fn () => app(Pipeline\PublishValidatedDraft::class)->beforeComplete($claim, $result, new \DateTimeImmutable));
                return (int) DB::table('estimate_generation_ai_role_runs')->where('organization_id', $scope[0])->where('session_id', $scope[2])
                    ->where('role', 'estimate_composer')->whereIn('physical_attempt_id', DB::table('estimate_generation_vision_physical_attempts')
                        ->where('credit_operation_id', $operationId)->select('attempt_id'))->sole()->id;
            });
        $this->useRealQuestionAuthorization();
        $session = $session->fresh();
        self::assertSame('estimate_review_required', $session->status->value);
        $headers = $this->sourceHeaders($actor);
        $url = '/api/v1/admin/projects/'.$scope[1].'/estimate-generation/sessions/'.$scope[2];
        $card = array_values(array_filter($this->getJson($url.'/questions', $headers)->assertOk()->json('data.items'),
            static fn (array $question): bool => ($question['source_locator']['kind'] ?? null) === 'composer_work_technology'))[0];
        self::assertSame([], $card['parameter_options']);
        $originalDocumentIds = $session->documents()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $answerInput = ['response' => 'other', 'other' => 'Способ монтажа пока не установлен; требуется дополнительный исходный документ.',
            'document_ids' => $originalDocumentIds, 'idempotency_key' => 'before-native-upload-'.$session->id,
            'expected_source_version' => $card['source_version'], 'answer_fingerprint' => $card['answer_fingerprint']];
        $this->postJson($url.'/questions/'.$card['code'].'/answer', $answerInput, $headers)->assertOk();
        $answered = array_values(array_filter($this->getJson($url.'/questions', $headers)->assertOk()->json('data.items'),
            static fn (array $question): bool => $question['code'] === $card['code']))[0];
        self::assertSame('answered', $answered['status']);
        self::assertSame($answerInput['other'], $answered['answer']['other']);
        self::assertFalse($answered['answer']['numeric_parameters_confirmed']);
        self::assertSame('unresolved', $answered['answer']['engineering_applicability']);
        $models = app(ProjectModelRepository::class);
        $originals = array_column(array_map(static fn (Fact $fact): array => get_object_vars($fact), $models->snapshot(...$scope)->facts), null, 'id');
        $history = [];
        foreach (['estimate_generation_project_model_assertions', 'estimate_generation_project_model_corrections',
            'estimate_generation_ai_role_runs'] as $table) {
            $history[$table] = DB::table($table)->where('organization_id', $scope[0])->where('project_id', $scope[1])
                ->where('session_id', $scope[2])->orderBy('id')->get()->all();
        }
        $originalEvidenceIds = array_map(static fn (string $id): int => (int) substr($id, strlen('evidence:')),
            array_merge(...array_map(static fn (Fact $fact): array => $fact->evidenceIds, $sources)));
        $history['estimate_generation_evidence'] = DB::table('estimate_generation_evidence')
            ->whereIn('id', $originalEvidenceIds)->orderBy('id')->get()->all();
        $oldState = (int) $session->fresh()->state_version;
        $oldOperation = (string) Str::uuid();
        $credits = app(EstimatorCreditOperations::class);
        $oldQuote = $credits->quote($actor, $scope[1], $scope[2], $oldState, $oldOperation, 'generation');
        self::assertSame('quoted', DB::table('estimate_generation_credit_operations')->where('operation_id', $oldOperation)->value('status'));
        $counts = [];
        foreach (['estimate_generation_ai_usage', 'estimate_generation_vision_physical_attempts', 'estimate_generation_credit_operations'] as $table) {
            $counts[$table] = DB::table($table)->where('organization_id', $scope[0])->where('session_id', $scope[2])->count();
        }
        $originalReader = app(Documents\DocumentUnitContentReader::class);
        (new \Tests\Support\EstimateGeneration\InMemoryS3HttpStorage)->install();
        foreach ([Documents\DocumentSourceManifestStorage::class, Documents\DocumentUnitContentReader::class,
            Documents\DocumentUnitDetector::class, Documents\DocumentUnitProcessor::class] as $service) {
            $this->app->forgetInstance($service);
        }
        $s3Reader = app(Documents\DocumentUnitContentReader::class);
        $this->app->instance(Documents\DocumentUnitContentReader::class,
            new class($originalReader, $s3Reader, $originalDocumentIds) implements Documents\DocumentUnitContentReader {
                public function __construct(private Documents\DocumentUnitContentReader $original,
                    private Documents\DocumentUnitContentReader $uploaded, private array $originalDocumentIds) {}

                public function read(Documents\DocumentUnitExecutionContext $context): string
                {
                    return in_array($context->documentId, $this->originalDocumentIds, true)
                        ? $this->original->read($context) : $this->uploaded->read($context);
                }
            });
        foreach ([ComposerSourceClaimValidationService::class, EstimateGenerationLearningEvidenceService::class,
            \App\BusinessModules\Addons\EstimateGeneration\Questions\ListEstimateClarifications::class,
            EstimateGenerationQuestionController::class] as $service) {
            $this->app->forgetInstance($service);
        }
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Исходное число')->fromArray([
            ['entity_key', 'parameter', 'value', 'unit'], ['quantity:clearance', 'quantity', '102', 'mm'],
        ]);
        $spreadsheet->getActiveSheet()->setCellValueExplicit('C2', '102', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $path = tempnam(sys_get_temp_dir(), 'most-review-length-');
        self::assertIsString($path);
        try {
            (new Xlsx($spreadsheet))->save($path);
            $bytes = file_get_contents($path);
            $digest = hash('sha256', $bytes);
        } finally {
            $spreadsheet->disconnectWorksheets();
            unlink($path);
        }
        Queue::fake();
        $requestId = (string) Str::uuid();
        $this->post($url.'/documents', ['state_version' => $oldState, 'request_id' => $requestId,
            'files' => [\Illuminate\Http\UploadedFile::fake()->createWithContent('synthetic-length.xlsx', $bytes)]], $headers)->assertOk();
        $document = $session->documents()->where('filename', 'synthetic-length.xlsx')->sole();
        self::assertSame('sha256:'.$digest, $document->source_version);
        self::assertSame($digest, $document->checksum_sha256);
        self::assertSame('processing_documents', $session->fresh()->status->value);
        self::assertNull($session->fresh()->input_payload['generation_attempt_id']);
        self::assertFalse($session->fresh()->input_payload['generation_requested']);
        Queue::assertNotPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class);
        $this->app->call([Queue::pushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob::class)->sole(), 'handle']);
        $this->app->call([Queue::pushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationUnitJob::class)->sole(), 'handle']);
        self::assertContains($session->fresh()->status->value, ['input_review_required', 'ready_to_generate']);
        $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $raw = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->type === 'quantity'
            && $fact->sourceVersion === $document->source_version));
        self::assertCount(1, $raw);
        self::assertSame([], array_values(array_filter($snapshot->facts,
            static fn (Fact $fact): bool => str_starts_with($fact->type, 'engineering_parameter_'))));
        self::assertSame(['102', 'mm', 'document', 'confirmed', []],
            [$raw[0]->value, $raw[0]->unit, $raw[0]->origin, $raw[0]->status, $raw[0]->semanticAttributes]);
        self::assertNotEmpty($raw[0]->nativeNumericProofs);
        $unit = $document->processingUnits()->with('page')->sole();
        self::assertSame('completed', $unit->status->value);
        $current = $session->fresh();
        $context = new Documents\DocumentUnitExecutionContext((int) $unit->id, ...[...$scope, (int) $document->id,
            $unit->unit_type, (int) $unit->unit_index, $unit->source_version, $unit->locator,
            $document->storage_path, $document->mime_type, $document->filename, '', (int) $unit->attempt_count,
            (int) $current->state_version, $current->status->value, (int) $unit->page->id]);
        $content = app(Documents\DocumentUnitContentReader::class)->read($context);
        $factory = new Documents\NativeNumericFactFactory;
        $descriptor = $factory->readLengthOperand($context, $content, $raw[0]->id, $models, app(EvidenceRepository::class));
        self::assertNotNull($descriptor);
        self::assertSame(['102', 'mm', 'C2', 'D2', 'xlsx:sheet:Исходное число!C2'],
            [$descriptor['value'], $descriptor['unit'], $descriptor['value_cell'], $descriptor['unit_cell'], $descriptor['native_reference']]);
        self::assertSame($raw[0]->id, $descriptor['raw_fact_id']);
        self::assertSame($document->source_version, $descriptor['document_source_version']);
        self::assertSame($digest, $descriptor['document_checksum_sha256']);
        $fresh = array_values(array_filter($this->getJson($url.'/questions', $headers)->assertOk()->json('data.items'),
            static fn (array $question): bool => $question['code'] === $card['code']));
        self::assertCount(1, $fresh);
        self::assertSame('answered', $fresh[0]['status']);
        self::assertSame($answered['answer'], $fresh[0]['answer']);
        self::assertCount(1, $fresh[0]['parameter_options']);
        self::assertSame([], $fresh[0]['parameter_checks']);
        $option = $fresh[0]['parameter_options'][0];
        self::assertSame(['102', 'mm', (int) $document->id, $document->source_version, 'C2', 'D2'],
            [$option['value'], $option['unit'], $option['source']['document_id'], $option['source']['source_version'],
                $option['source']['value_cell'], $option['source']['unit_cell']]);
        self::assertSame([], $fresh[0]['actions']);
        $this->postJson($url.'/questions/'.$card['code'].'/answer', [...$answerInput,
            'expected_source_version' => $answered['source_version'], 'answer_fingerprint' => $answered['answer_fingerprint'],
            'idempotency_key' => 'stale-after-native-upload-'.$session->id], $headers)->assertStatus(409);
        try {
            DB::transaction(fn () => $credits->begin($actor, $session->fresh(), $oldState, $oldOperation, $oldQuote['quote_id'], 'generation'));
            self::fail('The new document reused the old generation quote.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState) {
            self::assertGreaterThan($oldState, (int) $session->fresh()->state_version);
        }
        foreach ($counts as $table => $count) {
            self::assertSame($count, DB::table($table)->where('organization_id', $scope[0])->where('session_id', $scope[2])->count(), $table);
        }
        Queue::assertNotPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class);
        Http::assertNothingSent();
        if ($session->fresh()->status->value === 'input_review_required') {
            $this->postJson($url.'/confirm-input', ['state_version' => $session->fresh()->state_version], $headers)->assertOk();
        }
        self::assertSame('ready_to_generate', $session->fresh()->status->value);
        $work = ['kind' => 'supplementary', 'candidate_id' => null, 'work_key' => 'custom.installation',
            'name' => 'Монтаж неизвестной установки', 'work_section' => 'equipment', 'unit' => 'count',
            'derived_quantity_id' => null, 'source_fact_ids' => [$initialAnswer->id, $sources[0]->id],
            'entity_id' => $sources[0]->entityId, 'technology_package_candidate' => null,
            'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => []];
        $response = static fn (array $content): \GuzzleHttp\Psr7\Response => new \GuzzleHttp\Psr7\Response(200,
            ['Content-Type' => 'application/json'], json_encode(['id' => 'chatcmpl-review-upload-fixture',
                'object' => 'chat.completion', 'created' => 1, 'model' => 'openai/gpt-6-luna',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => json_encode($content, JSON_THROW_ON_ERROR)],
                    'finish_reason' => 'stop', 'logprobs' => null]],
                'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 100, 'total_tokens' => 1100]], JSON_THROW_ON_ERROR));
        $wireHistory = [];
        $wireHandler = new \GuzzleHttp\Handler\MockHandler([
            $response(['work_intents' => [$work], 'claim_candidates' => []]), $response(['findings' => []]),
        ]);
        $stack = \GuzzleHttp\HandlerStack::create($wireHandler);
        $stack->push(\GuzzleHttp\Middleware::history($wireHistory));
        config(['ai-assistant.llm.timeweb.api_key' => 'fixture-not-a-real-key',
            'ai-assistant.llm.timeweb.base_uri' => 'https://timeweb.example.test/v1']);
        $this->app->instance(RerankWireClient::class, new \App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebRerankWireClient(
            app(\App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebChatCompletionPayloadFactory::class),
            app(\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostGuard::class),
            app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\DurableAiPhysicalResponseStore::class),
            httpClient: new \GuzzleHttp\Client(['handler' => $stack])));
        foreach ([TimewebEstimateComposerModel::class, RunEstimateComposer::class,
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditModel::class,
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\RunEstimateAudit::class,
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle::class,
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposerCorrection::class] as $service) {
            $this->app->forgetInstance($service);
        }
        $newOperation = (string) Str::uuid();
        $quote = $this->postJson($url.'/operations/quote', ['request_id' => $newOperation,
            'state_version' => $session->fresh()->state_version, 'operation_kind' => 'generation',
            'operation_parameters' => ['max_work_items' => 4]], $headers)->assertOk()->json('data');
        self::assertNotSame($oldQuote['quote_id'], $quote['quote_id']);
        self::assertSame('quoted', DB::table('estimate_generation_credit_operations')->where('operation_id', $newOperation)->value('status'));
        self::assertCount(0, $wireHistory);
        Queue::assertNotPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class);
        $this->postJson($url.'/generate', ['request_id' => $newOperation, 'quote_id' => $quote['quote_id'],
            'state_version' => $session->fresh()->state_version, 'generation_mode' => 'ai_assisted'], $headers)->assertSuccessful();
        self::assertSame('generating', $session->fresh()->status->value);
        $job = Queue::pushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class)->sole();
        foreach (Pipeline\ProcessingStage::cases() as $stage) {
            $job->handle(app(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RunEstimateGenerationDraft::class));
        }
        self::assertSame(9, DB::table('estimate_generation_pipeline_checkpoints')->where('session_id', $scope[2])
            ->where('generation_attempt_id', $newOperation)->where('status', 'completed')->count());
        self::assertSame(0, $wireHandler->count());
        self::assertCount(2, $wireHistory);
        self::assertSame('settled', DB::table('estimate_generation_credit_operations')->where('operation_id', $newOperation)->value('status'));
        self::assertSame($counts['estimate_generation_credit_operations'] + 1,
            DB::table('estimate_generation_credit_operations')->where('organization_id', $scope[0])->where('session_id', $scope[2])->count());
        foreach (['estimate_generation_ai_usage', 'estimate_generation_vision_physical_attempts'] as $table) {
            self::assertSame($counts[$table] + 2, DB::table($table)->where('organization_id', $scope[0])->where('session_id', $scope[2])->count(), $table);
        }
        $review = array_values(array_filter($this->getJson($url.'/questions', $headers)->assertOk()->json('data.items'),
            static fn (array $question): bool => $question['code'] === $card['code']));
        self::assertSame('estimate_review_required', $session->fresh()->status->value);
        self::assertCount(1, $review);
        self::assertSame('answered', $review[0]['status']);
        self::assertSame($answered['answer'], $review[0]['answer']);
        self::assertSame(['reanswer', 'revoke'], $review[0]['actions']);
        self::assertNotSame($answered['answer_fingerprint'], $review[0]['answer_fingerprint']);
        self::assertCount(1, $review[0]['parameter_options']);
        self::assertSame([$option['value'], $option['unit'], $option['source']],
            [$review[0]['parameter_options'][0]['value'], $review[0]['parameter_options'][0]['unit'], $review[0]['parameter_options'][0]['source']]);
        self::assertNotEmpty($review[0]['parameter_options'][0]['source_operand_ref']);
        self::assertSame([], $review[0]['parameter_checks']);
        $projectInput = json_decode((string) $wireHistory[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $workContexts = json_decode($projectInput['messages'][1]['content'], true, flags: JSON_THROW_ON_ERROR)['project']['work_technology_context'];
        self::assertCount(1, $workContexts);
        self::assertSame($answered['answer'], $workContexts[0]['answer']);
        $queuedAfterGeneration = Queue::pushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class)->count();
        $this->postJson($url.'/documents/'.$document->id.'/ignore', ['state_version' => $session->fresh()->state_version], $headers)->assertOk();
        self::assertNull($factory->readLengthOperand($context, $content, $raw[0]->id, $models, app(EvidenceRepository::class)));
        $afterIgnore = array_values(array_filter($this->getJson($url.'/questions', $headers)->assertOk()->json('data.items'),
            static fn (array $question): bool => $question['code'] === $card['code']));
        self::assertCount(1, $afterIgnore);
        self::assertSame('answered', $afterIgnore[0]['status']);
        self::assertSame($answered['answer'], $afterIgnore[0]['answer']);
        self::assertSame([], $afterIgnore[0]['parameter_options']);
        self::assertSame($initialAnswer->id, $models->fact(...[...$scope, $initialAnswer->id])->id);
        foreach ($originals as $id => $original) {
            self::assertSame($original, get_object_vars($models->fact(...[...$scope, $id])), $id);
        }
        foreach ($history as $table => $rows) {
            self::assertEquals($rows, DB::table($table)->whereIn('id', array_column($rows, 'id'))->orderBy('id')->get()->all(), $table);
        }
        foreach ($counts as $table => $count) {
            self::assertSame($count + ($table === 'estimate_generation_credit_operations' ? 1 : 2),
                DB::table($table)->where('organization_id', $scope[0])->where('session_id', $scope[2])->count(), $table);
        }
        self::assertCount($queuedAfterGeneration,
            Queue::pushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class));
        Http::assertNothingSent();
    }

    public static function parameterWorkSections(): array
    {
        return ['equipment' => ['equipment'], 'heating' => ['heating']];
    }

    public function test_requirement_only_source_is_published_selected_settled_and_reused_in_the_next_actual_composer(): void
    {
        Queue::fake();
        config(['cache.default' => 'array', 'web_auth.origins.admin' => ['https://admin.fixture.test']]);
        $rights = AssistantRealAuthorizationFixture::create(['working-entry']);
        $rights->memberRole->update(['module_permissions' => ['ai-estimates' => ['estimate_generation.create', 'estimate_generation.generate',
            'estimate_generation.view', 'estimate_generation.review', 'estimate_generation.upload_documents']], 'system_permissions' => ['admin.access']]);
        $rights->member->organizations()->updateExistingPivot($rights->organization->id, ['project_access_mode' => 'all_projects']);
        [$actor, $session, $initialAnswer, $sources] = $this->answeredSourceFixture(naturalInitial: true, targetMode: 'selected',
            realActor: $rights->member, lengthOperand: false);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $organization = Organization::query()->findOrFail($scope[0]);
        $project = Project::query()->findOrFail($scope[1]);
        $models = app(ProjectModelRepository::class);
        $image = imagecreatetruecolor(32, 32);
        ob_start();
        imagepng($image);
        $content = (string) ob_get_clean();
        $digest = hash('sha256', $content);
        $version = 'sha256:'.$digest;
        $document = EstimateGenerationDocument::query()->create(['organization_id' => $scope[0], 'project_id' => $scope[1],
            'session_id' => $scope[2], 'user_id' => $actor->id, 'filename' => 'synthetic-requirement.png', 'mime_type' => 'image/png',
            'storage_path' => 'org-'.$scope[0].'/synthetic-requirement.png', 'source_version' => $version,
            'checksum_sha256' => $digest, 'status' => 'ready']);
        $unit = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit::query()->create([
            'organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2], 'document_id' => $document->id,
            'unit_type' => 'raster_image', 'unit_index' => 1, 'source_version' => $version, 'status' => 'pending',
            'locator' => ['source_kind' => 'image', 'source_version' => $version, 'coordinate_space' => 'image_pixels',
                'artifact_path' => $document->storage_path, 'artifact_source_version' => $version, 'artifact_sha256' => $version,
                'artifact_version_id' => 'synthetic-requirement-v1', 'artifact_bytes' => strlen($content), 'content_type' => 'image/png']]);
        $page = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage::query()->create([
            'organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2], 'document_id' => $document->id,
            'processing_unit_id' => $unit->id, 'page_number' => 1, 'source_version' => $version, 'status' => 'ready',
            'language_codes' => [], 'normalized_payload' => [], 'quality_flags' => []]);
        $polygon = [[0.25, 0.25], [0.75, 0.25], [0.75, 0.75], [0.25, 0.75]];
        $value = static fn (?string $data): array => ['data' => $data, 'evidenceRef' => $data === null ? null : 'source',
            'sourcePolygonOrNativeRef' => $data === null ? null : $polygon];
        $proposal = ['schemaVersion' => 1, 'kind' => 'source_requirement', 'parameter' => $value('Сервисный зазор'),
            'dimension' => 'length', 'comparator' => $value('gte'), 'threshold' => $value('0.90'), 'unit' => $value('m'),
            'appliesTo' => ['subject' => $value('Синтетический узел в исходном документе'), 'equipmentIdentity' => null],
            'documentEdition' => $value(null), 'clause' => $value('Синтетический пункт 1'), 'conditions' => null];
        $provider = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionProvider::class);
        $provider->method('analyze')->willReturnCallback(function (\App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput $input) use ($proposal, $polygon): \App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData {
            ($input->onPhysicalAttemptReserved)((string) Str::uuid());
            $evidence = ['key' => 'source', 'locator' => ['page_id' => $input->pageId, 'page_number' => $input->pageNumber,
                'processing_unit_id' => $input->processingUnitId, 'source_version' => $input->sourceVersion,
                'coordinate_space' => 'normalized_derivative_v1', 'explicit' => true]];
            $arbitration = $input->auxiliaryMetadata['arbitration'] ?? null;
            if ($arbitration !== null) {
                $decisions = array_map(static fn (array $claim): array => ['claim_id' => $claim['id'],
                    'status' => $claim['role'] === 'observer_construction' ? 'accepted' : 'candidate',
                    'supporting_claim_ids' => [$claim['id']], 'evidence_refs' => [$claim['evidence_ref']],
                    'reason_code' => 'controlled_source_excerpt'], $arbitration['claims']);
                return new \App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData('detail',
                    [\App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionEvidenceData::fromArray($evidence)], [], [], ['scale_missing'],
                    'recorded', 'openai/gpt-6-luna', 'openai/gpt-6-luna', 'synthetic-requirement:v1', 'measured', 1, 1, rawObserverFacts: $decisions);
            }
            $construction = $input->auxiliaryMetadata['observer']['profile'] === 'construction';
            $literal = $input->auxiliaryMetadata['observer']['profile'] === 'literal';
            $contract = $construction ? 'sheet-analysis:v4' : 'sheet-analysis:v3';
            return \App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData::fromProviderArray([
                'schema_version' => 3, 'sheet_type' => 'detail', 'evidence' => [$evidence], 'elements' => [], 'scale_candidates' => [],
                'warnings' => ['scale_missing'], 'visual_attributes' => [], 'project_sheet_analysis' => ['contractVersion' => $contract,
                    'role' => 'unknown', 'facts' => ($construction || $literal) ? [['entityKey' => 'wall:source-rule', 'factType' => 'technology_requirements',
                        'value' => ['type' => 'string', 'data' => $construction
                            ? 'Синтетический пункт: зазор не менее 0.90 м; применимость к проекту не установлена.'
                            : 'Второе контролируемое наблюдение: текст исходного пункта не установлен.'],
                        'unit' => null, 'evidenceRef' => 'source', 'sourcePolygonOrNativeRef' => $polygon, 'confidence' => 0.9,
                        'contractVersion' => $contract, ...($construction ? ['semanticProposal' => $proposal] : [])]] : []]],
                'recorded', 'openai/gpt-6-luna', 'openai/gpt-6-luna', 'synthetic-requirement:v1', 'measured', 1, 1, 64);
        });
        $objects = [(string) $document->storage_path => $content];
        $files = $this->createMock(\App\Services\Storage\FileService::class);
        $files->method('describeCurrent')->willReturnCallback(static function (string $path) use (&$objects): array {
            $body = $objects[$path];
            return ['body' => $body, 'size' => strlen($body), 'sha256' => hash('sha256', $body), 'content_type' => 'image/png'];
        });
        $files->method('putImmutable')->willReturnCallback(static function (string $path, string $body, string $contentType) use (&$objects): array {
            $objects[$path] = $body;
            return ['size' => strlen($body), 'sha256' => hash('sha256', $body)];
        });
        $reader = new \App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader($files);
        $runs = app(AiRoleRunRepository::class);
        $processor = new Documents\ProductionDocumentUnitProcessor(app(Documents\OcrDocumentUnitProcessor::class),
            $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\CadGeometryProvider::class),
            new \App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\RasterPreprocessor($files, $reader), $reader,
            new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\RunIndependentObservers($runs, $provider,
                new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\ObserverInputBuilder, 'openai/gpt-6-luna'),
            new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\RunDocumentArbitration($runs, $provider,
                new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationInputBuilder, 'openai/gpt-6-luna'));
        $store = app(Documents\DocumentProcessingUnitStore::class);
        $now = now()->toDateTimeImmutable();
        $claim = $store->claim((int) $unit->id, $version, $now, $now->modify('+300 seconds'), 3);
        self::assertSame(Documents\DocumentProcessingUnitClaimStatus::Acquired, $claim->status);
        $context = $store->executionContext($claim);
        self::assertNotNull($context);
        $output = $processor->process($context);
        self::assertNotNull($output->publication);
        self::assertCount(2, $output->publication->claims);
        self::assertCount(1, array_filter($output->publication->decisions, static fn ($decision): bool => $decision->status === 'accepted'));
        self::assertSame('arbiter', $output->normalizedPayload['document_arbitration']['role']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $output->version);
        self::assertNotSame($version, $output->version);
        self::assertTrue($store->publish($claim, $output, now()->toDateTimeImmutable()));
        self::assertSame($output->version, $unit->fresh()->output_version);
        self::assertSame($output->version, $page->fresh()->output_version);
        self::assertCount(1, array_filter($page->fresh()->normalized_payload['document_arbitration']['decisions'],
            static fn (array $decision): bool => $decision['status'] === 'candidate'));
        $winner = array_values(array_filter($output->publication->claims, static fn ($claim): bool => $claim->observerRole === 'observer_construction'))[0];
        $captured = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $sourceFacts = array_values(array_filter($captured->facts, static fn (Fact $fact): bool => $fact->type === 'technology_requirements' && $fact->status === 'confirmed'));
        self::assertCount(1, $sourceFacts);
        $sourceFact = $sourceFacts[0];
        self::assertSame($winner->value['data'], $sourceFact->value);
        foreach ($sourceFact->evidenceIds as $proofId) {
            $proof = app(EvidenceRepository::class)->node(...[...$scope, (int) substr($proofId, 9)]);
            self::assertNotNull($proof);
            self::assertSame('material:'.hash('sha256', json_encode($winner->value['data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                $proof->value['fact_value']);
        }
        self::assertSame([], $sourceFact->nativeNumericProofs);
        self::assertNull($sourceFact->unit);
        $entity = array_column($captured->entities, null, 'id')[$sourceFact->entityId];
        $descriptor = $entity->attributes['properties']['document_semantic_proposal'];
        self::assertSame('proposed', $descriptor['status']);
        self::assertNull($descriptor['payload']['appliesTo']['equipmentIdentity']);
        $originalFact = get_object_vars($sourceFact);
        $originalEntity = get_object_vars($entity);
        $originalEvidence = array_map('get_object_vars', array_values(array_filter($captured->evidence,
            static fn ($proof): bool => in_array($proof->id, $sourceFact->evidenceIds, true))));
        self::assertNotEmpty($originalEvidence);
        $nativeReader = app(Documents\DocumentUnitContentReader::class);
        $this->app->instance(Documents\DocumentUnitContentReader::class, new class($nativeReader, (int) $document->id, $content) implements Documents\DocumentUnitContentReader
        {
            public function __construct(private Documents\DocumentUnitContentReader $native, private int $documentId, private string $content) {}

            public function read(Documents\DocumentUnitExecutionContext $context): string
            {
                return $context->documentId === $this->documentId ? $this->content : $this->native->read($context);
            }
        });
        foreach ([ComposerSourceClaimValidationService::class, EstimateGenerationLearningEvidenceService::class,
            EstimateGenerationLearningRecorder::class, EstimateComposerInputFactory::class] as $service) {
            $this->app->forgetInstance($service);
        }
        $url = '/api/v1/admin/projects/'.$scope[1].'/estimate-generation/sessions/'.$scope[2];
        $headers = $this->sourceHeaders($actor);
        $workCard = fn (array $items): array => array_values(array_filter($items,
            static fn (array $item): bool => ($item['source_locator']['kind'] ?? null) === 'composer_work_technology'))[0];
        $counts = fn (): array => array_map(fn (string $table): int => DB::table($table)->where('session_id', $scope[2])->count(),
            ['estimate_generation_ai_usage', 'estimate_generation_ai_role_runs', 'estimate_generation_credit_attempts']);
        $factory = fn (?string $local = null) => app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null,
            $session->fresh()->input_payload['selected_sections'], 'universal', $session->fresh()->input_payload, $local]);
        $inputs = [];
        $savedAnswer = $answerInput = [];
        $answerId = $selectedRef = null;
        foreach ([0, 1, 2, 3] as $phase) {
            [$session, $operationId] = $this->paidReceipt(RunEstimateComposer::ANSWER_PROMPT_CONTRACT, [], true,
                [$organization, $project, $actor, $session->fresh()], false,
                function (EstimateGenerationSession $current, string $operationId) use ($actor, $scope, $initialAnswer, $sources, $phase, &$inputs): int {
                    $work = ['kind' => 'supplementary', 'candidate_id' => null, 'work_key' => $phase === 3 ? 'custom.replacement' : 'custom.installation',
                        'name' => 'Монтаж неизвестной установки', 'work_section' => 'equipment', 'unit' => 'count',
                        'derived_quantity_id' => null, 'source_fact_ids' => [$initialAnswer->id, $sources[0]->id],
                        'entity_id' => $sources[0]->entityId, 'technology_package_candidate' => null,
                        'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => [],
                        ...(in_array($phase, [0, 3], true) ? ['technology_clarification' => ['question' => 'Какое исходное условие следует учитывать для этой работы?',
                            'reason' => 'Применимость условия и способ монтажа не установлены.']] : [])];
                    $history = [];
                    $composer = new RunEstimateComposer(app(AiRoleRunRepository::class), new TimewebEstimateComposerModel(
                        $this->parameterSdkWire($work, $history), app(EloquentAiUsageStore::class), app(AiPriceSnapshotResolver::class),
                        'openai/gpt-6-luna', 524288, 8192, 60), 'openai/gpt-6-luna', allowance: app(EstimateComposerAllowance::class));
                    [$claim, $result] = $this->naturalQuestionStages($actor, $current, $operationId, $composer, true);
                    self::assertCount(1, $history);
                    $body = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
                    $inputs[$phase] = json_decode($body['messages'][1]['content'], true, flags: JSON_THROW_ON_ERROR)['project'];
                    self::assertSame([], array_values(array_filter($inputs[$phase]['facts'],
                        static fn (array $fact): bool => str_starts_with($fact['type'], 'engineering_parameter_'))));
                    DB::transaction(fn () => app(Pipeline\PublishValidatedDraft::class)->beforeComplete($claim, $result, new \DateTimeImmutable));
                    self::assertSame('settled', DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->value('status'));
                    return (int) DB::table('estimate_generation_ai_role_runs')->where('organization_id', $scope[0])->where('session_id', $scope[2])
                        ->where('role', 'estimate_composer')->whereIn('physical_attempt_id', DB::table('estimate_generation_vision_physical_attempts')
                            ->where('credit_operation_id', $operationId)->select('attempt_id'))->sole()->id;
                });
            $this->useRealQuestionAuthorization();
            if ($phase === 0) {
                $beforeRead = $counts();
                $card = $workCard($this->getJson($url.'/questions', $headers)->assertOk()->json('data.items'));
                self::assertCount(1, $card['requirement_options'] ?? [], 'Actual published generic source must be offered without an observed numeric operand.');
                self::assertSame([], $card['parameter_options']);
                $option = $card['requirement_options'][0];
                $selectedRef = $option['source_requirement_ref'];
                self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $selectedRef);
                self::assertSame('0.90', $option['source_details']['threshold']);
                self::assertSame('m', $option['source_details']['unit']);
                self::assertNull($option['source_details']['equipment_identity']);
                self::assertSame((int) $document->id, $option['source_details']['source']['document_id']);
                $oldState = (int) $session->fresh()->state_version;
                $oldOperation = (string) Str::uuid();
                $credits = app(EstimatorCreditOperations::class);
                $oldQuote = $credits->quote($actor, $scope[1], $scope[2], $oldState, $oldOperation, 'generation');
                $beforeAnswerOperations = DB::table('estimate_generation_credit_operations')->where('session_id', $scope[2])->count();
                $answerInput = ['response' => 'other', 'other' => 'Выбираю синтетический исходный пункт для обсуждения; применимость не подтверждена.',
                    'document_ids' => [(int) $document->id], 'idempotency_key' => 'requirement-only-'.$session->id,
                    'expected_source_version' => $card['source_version'], 'answer_fingerprint' => $card['answer_fingerprint'],
                    'parameter' => ['schema_version' => 2, 'condition' => 'minimum_length', 'source_requirement_ref' => $selectedRef]];
                $answerUrl = $url.'/questions/'.$card['code'].'/answer';
                $this->postJson($answerUrl, $answerInput, $headers)->assertOk();
                $freshCard = $workCard($this->getJson($url.'/questions', $headers)->assertOk()->json('data.items'));
                $savedAnswer = $freshCard['answer'];
                $answerId = $savedAnswer['fact_id'];
                self::assertSame('answered', $freshCard['status']);
                self::assertSame($selectedRef, $savedAnswer['requirement']['selected_ref']);
                self::assertSame($selectedRef, $freshCard['requirement_options'][0]['source_requirement_ref']);
                self::assertSame('user_confirmed', $savedAnswer['requirement']['confirmation_level']);
                self::assertSame('current', $savedAnswer['requirement']['source_details']['status']);
                foreach (['source_correspondence', 'source_authority', 'edition_confirmation', 'engineering_applicability'] as $axis) {
                    self::assertSame('unresolved', $savedAnswer['requirement']['source_details'][$axis]);
                }
                self::assertFalse($savedAnswer['requirement']['source_details']['numeric_inputs_verified']);
                self::assertFalse($savedAnswer['requirement']['source_details']['numeric_parameters_confirmed']);
                $written = $models->fact(...[...$scope, $answerId]);
                self::assertSame(CanonicalPipelineJson::encode($answerInput['parameter']), CanonicalPipelineJson::encode($written->value['parameter']['input']));
                self::assertSame($sourceFact->id, $written->value['parameter']['association']['source_fact_id']);
                self::assertArrayNotHasKey('observed_fact_id', $written->value['parameter']);
                self::assertArrayNotHasKey('requirement_fact_id', $written->value['parameter']);
                $stored = DB::table('estimate_generation_project_model_assertions')->where('session_id', $scope[2])->count();
                $this->postJson($answerUrl, $answerInput, $headers)->assertOk();
                self::assertSame($stored, DB::table('estimate_generation_project_model_assertions')->where('session_id', $scope[2])->count());
                $this->postJson($answerUrl, [...$answerInput, 'other' => 'Другое тело с тем же UUID.'], $headers)->assertStatus(422);
                $this->postJson($answerUrl, [...$answerInput, 'idempotency_key' => 'stale-own-cas'], $headers)->assertStatus(409);
                $receipt = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetClarificationAnswerReceipt::class)
                    ->handle($actor, $scope[1], $scope[2], $card['code'], array_intersect_key($answerInput,
                        array_flip(['idempotency_key', 'expected_source_version', 'answer_fingerprint', 'document_ids'])));
                self::assertSame('saved', $receipt['status']);
                self::assertSame('0', $receipt['cost_units']);
                self::assertSame(CanonicalPipelineJson::encode($answerInput['parameter']), CanonicalPipelineJson::encode($receipt['parameter']));
                self::assertSame($beforeRead, $counts());
                self::assertSame($beforeAnswerOperations, DB::table('estimate_generation_credit_operations')->where('session_id', $scope[2])->count());
                Queue::assertNotPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class);
                self::assertSame(0, EstimateGenerationLearningExample::query()->where('generation_session_id', $scope[2])->count());
                self::assertSame(0, DB::transaction(fn () => app(EstimateGenerationLearningRecorder::class)->recordComposerSourceClaims(...[...$scope, $operationId])));
                self::assertGreaterThan((int) DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->value('approved_state_version'),
                    $written->value['composer_context']['state_version']);
                try {
                    DB::transaction(fn () => $credits->begin($actor, $session->fresh(), $oldState, $oldOperation, $oldQuote['quote_id'], 'generation'));
                    self::fail('A later human answer reused the old generation approval.');
                } catch (\App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState) {
                    self::assertGreaterThan($oldState, (int) $session->fresh()->state_version);
                } catch (\DomainException $exception) {
                    self::assertSame('estimator_known_context_changed', $exception->getMessage());
                }
                self::assertSame('quoted', DB::table('estimate_generation_credit_operations')->where('operation_id', $oldOperation)->value('status'));
                self::assertSame($beforeRead, $counts());
            }
            if ($phase === 1) {
                self::assertArrayNotHasKey('component', $inputs[1]['work_technology_context'][0]['answer']['requirement']);
                $row = EstimateGenerationLearningExample::query()->where('generation_session_id', $scope[2])->sole();
                self::assertSame('composer_source_claim', $row->source_type);
                self::assertSame('estimate_session', $row->source_entity_type);
                self::assertSame('work_parameter_component:v2', $row->context_payload['origin']);
                self::assertSame(2, $row->context_payload['schema_version']);
                self::assertFalse($row->context_payload['training_allowed']);
                self::assertSame('proposed', $row->decision_status);
                self::assertFalse($row->is_positive);
                self::assertNull($row->accepted_at);
                foreach (['norm_code', 'estimate_norm_id', 'normative_dataset_version_id'] as $column) {
                    self::assertNull($row->{$column});
                }
                self::assertContains('do_not_index', $row->quality_flags);
                self::assertFalse(EstimateGenerationLearningSourceTrustPolicy::isIndexable($row));
                $constraint = fn (): string => DB::selectOne("SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = 'estimate_generation_learning_examples'::regclass AND conname = 'estimate_learning_source_claim_shape'")->definition;
                $beforeConstraint = $constraint();
                foreach ([['is_positive' => true], ['context_payload' => json_encode([...$row->context_payload, 'training_allowed' => true], JSON_THROW_ON_ERROR)]] as $change) {
                    try {
                        DB::transaction(fn () => DB::table('estimate_generation_learning_examples')->where('id', $row->id)->update($change));
                        self::fail('A v2 requirement escaped the closed proposed/nonpositive storage guard.');
                    } catch (QueryException $exception) {
                        self::assertSame('23514', $exception->errorInfo[0]);
                        self::assertStringContainsString('estimate_learning_source_claim_shape', $exception->errorInfo[2]);
                    }
                }
                $beforeRows = DB::table('estimate_generation_learning_examples')->where('generation_session_id', $scope[2])->orderBy('id')->get()->toJson();
                $migration = require base_path('app/BusinessModules/Addons/EstimateGeneration/migrations/2026_10_24_000300_allow_requirement_only_component_learning.php');
                try {
                    $migration->down();
                    self::fail('Rollback must preserve actual terminal v2 history.');
                } catch (\RuntimeException $exception) {
                    self::assertSame('requirement_only_component_learning_requires_preserved_history', $exception->getMessage());
                }
                self::assertSame($beforeConstraint, $constraint());
                self::assertSame($beforeRows, DB::table('estimate_generation_learning_examples')->where('generation_session_id', $scope[2])->orderBy('id')->get()->toJson());
            }
            if ($phase === 2) {
                $row = EstimateGenerationLearningExample::query()->where('generation_session_id', $scope[2])->sole();
                $component = $inputs[2]['work_technology_context'][0]['answer']['requirement']['component'];
                self::assertSame((int) $row->id, $component['learning_example_id']);
                self::assertSame($answerId, $component['source_answer_fact_id']);
                self::assertSame($selectedRef, $component['selected_ref']);
                self::assertSame('0.90', $component['threshold']);
                self::assertSame('m', $component['unit']);
                self::assertNull($component['observed']);
                self::assertNull($component['comparison']);
                self::assertArrayNotHasKey('meets_minimum', $component);
                self::assertFalse($component['numeric_inputs_verified']);
                self::assertSame('unresolved', $component['engineering_applicability']);
                self::assertSame('user_confirmed', $component['confirmation_level']);
                self::assertSame('user_assumption', $component['interpretation_status']);
                self::assertSame([], $inputs[2]['source_claim_refs'] ?? []);
                $card = $workCard($this->getJson($url.'/questions', $headers)->assertOk()->json('data.items'));
                self::assertSame($savedAnswer, $card['answer']);
                self::assertCount(1, $card['requirement_checks']);
                self::assertSame($selectedRef, $card['requirement_checks'][0]['selected_ref']);
                $opaque = ['schema_version' => 2, 'selected_ref' => $selectedRef, 'confirmation_level' => 'user_confirmed', 'source_details' => ['status' => 'unavailable']];
                $beforeRead = $counts();
                $noActor = $workCard(app(\App\BusinessModules\Addons\EstimateGeneration\Questions\ListEstimateClarifications::class)->handle(...$scope));
                self::assertSame($opaque, $noActor['answer']['requirement']);
                self::assertSame([], $noActor['requirement_options']);
                self::assertSame([], $noActor['requirement_checks']);
                $reviewer = $rights->addMember(['ai-estimates' => ['estimate_generation.review']], ['admin.access']);
                $reviewer->organizations()->updateExistingPivot($scope[0], ['project_access_mode' => 'all_projects']);
                $reviewHeaders = $this->sourceHeaders($reviewer);
                $limited = $workCard($this->getJson($url.'/questions', $reviewHeaders)->assertOk()->json('data.items'));
                self::assertSame($opaque, $limited['answer']['requirement']);
                self::assertSame([], $limited['requirement_options']);
                self::assertSame([], $limited['requirement_checks']);
                $this->postJson($url.'/questions/'.$card['code'].'/answer', [...$answerInput, 'idempotency_key' => 'requirement-without-view',
                    'answer_fingerprint' => $card['answer_fingerprint']], $reviewHeaders)->assertForbidden();
                self::assertContains($this->getJson($url.'/questions', $this->sourceHeaders($rights->foreignOwner))->status(), [403, 404]);
                DB::beginTransaction();
                try {
                    $nested = $workCard($this->getJson($url.'/questions', $headers)->assertOk()->json('data.items'));
                    self::assertSame($opaque, $nested['answer']['requirement']);
                    self::assertSame([], $nested['requirement_options']);
                    self::assertSame([], $nested['requirement_checks']);
                    self::assertSame('off', DB::selectOne('SHOW transaction_read_only')->transaction_read_only);
                } finally {
                    DB::rollBack();
                }
                self::assertSame($beforeRead, $counts());
                self::assertSame([], $factory('foreign-local')->workTechnologyContext);
                self::assertArrayHasKey('component', $factory()->workTechnologyContext[0]['answer']['requirement']);
                $historyRows = fn (): string => DB::table('estimate_generation_learning_examples')->where('generation_session_id', $scope[2])->orderBy('id')->get()->toJson();
                $beforeHistory = $historyRows();
                DB::beginTransaction();
                try {
                    $this->postJson($url.'/documents/'.$document->id.'/ignore', ['state_version' => $session->fresh()->state_version], $headers)->assertOk();
                    $ignored = $workCard($this->getJson($url.'/questions', $headers)->assertOk()->json('data.items'));
                    self::assertSame([], $ignored['requirement_options']);
                    self::assertSame([], $ignored['requirement_checks']);
                    self::assertSame($opaque, $ignored['answer']['requirement']);
                    $this->postJson($url.'/questions/'.$ignored['code'].'/answer', [...$answerInput, 'idempotency_key' => 'ignored-requirement',
                        'expected_source_version' => $ignored['source_version'], 'answer_fingerprint' => $ignored['answer_fingerprint']], $headers)->assertStatus(409);
                    self::assertArrayNotHasKey('component', $factory()->workTechnologyContext[0]['answer']['requirement'] ?? []);
                    self::assertSame($beforeHistory, $historyRows());
                } finally {
                    DB::rollBack();
                }
                $card = $workCard($this->getJson($url.'/questions', $headers)->assertOk()->json('data.items'));
                $this->postJson($url.'/questions/'.$card['code'].'/answer', ['response' => 'revoke', 'idempotency_key' => 'requirement-revoke',
                    'expected_source_version' => $card['source_version'], 'answer_fingerprint' => $card['answer_fingerprint']], $headers)->assertOk();
                self::assertArrayNotHasKey('requirement', $factory()->workTechnologyContext[0]['answer']);
                self::assertSame($beforeHistory, $historyRows());
            }
        }
        self::assertSame('revoke', $inputs[3]['work_technology_context'][0]['answer']['response']);
        self::assertArrayNotHasKey('requirement', $inputs[3]['work_technology_context'][0]['answer']);
        $this->useRealQuestionAuthorization();
        $replacement = $workCard($this->getJson($url.'/questions', $headers)->assertOk()->json('data.items'));
        self::assertSame('unanswered', $replacement['status']);
        self::assertNull($replacement['answer']);
        self::assertNotSame($selectedRef, $replacement['requirement_options'][0]['source_requirement_ref']);
        $this->postJson($url.'/questions/'.$replacement['code'].'/answer', [...$answerInput, 'idempotency_key' => 'replacement-old-source-ref',
            'expected_source_version' => $replacement['source_version'], 'answer_fingerprint' => $replacement['answer_fingerprint']], $headers)->assertStatus(409);
        $final = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        self::assertSame([], array_values(array_filter($final->facts, static fn (Fact $fact): bool => str_starts_with($fact->type, 'engineering_parameter_'))));
        self::assertSame($originalFact, get_object_vars($models->fact(...[...$scope, $sourceFact->id])));
        self::assertSame($originalEntity, get_object_vars(array_column($final->entities, null, 'id')[$sourceFact->entityId]));
        self::assertSame($originalEvidence, array_map('get_object_vars', array_values(array_filter($final->evidence,
            static fn ($proof): bool => in_array($proof->id, $sourceFact->evidenceIds, true)))));
        Http::assertNothingSent();
    }

    #[DataProvider('parameterWorkSections')]
    public function test_typed_work_parameter_uses_actual_native_source_and_terminal_learning_in_the_next_composer(string $workSection): void
    {
        Queue::fake();
        config(['cache.default' => 'array', 'web_auth.origins.admin' => ['https://admin.fixture.test']]);
        $rights = AssistantRealAuthorizationFixture::create(['working-entry']);
        $rights->memberRole->update(['module_permissions' => ['ai-estimates' => ['estimate_generation.create', 'estimate_generation.generate',
            'estimate_generation.view', 'estimate_generation.review', 'estimate_generation.upload_documents']], 'system_permissions' => ['admin.access']]);
        $rights->member->organizations()->updateExistingPivot($rights->organization->id, ['project_access_mode' => 'all_projects']);
        [$actor, $session, $initialAnswer, $sources] = $this->answeredSourceFixture(naturalInitial: true, targetMode: 'selected',
            selectedSections: array_values(array_unique(['equipment', $workSection])), realActor: $rights->member, lengthOperand: true);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $organization = Organization::query()->findOrFail($scope[0]);
        $project = Project::query()->findOrFail($scope[1]);
        $models = app(ProjectModelRepository::class);
        $raw = array_values(array_filter($models->snapshotForPlanning(...[...$scope, 10001])['snapshot']->facts,
            static fn (Fact $fact): bool => $fact->type === 'quantity' && $fact->unit === 'mm'));
        self::assertCount(1, $raw);
        self::assertNotEmpty($raw[0]->nativeNumericProofs);
        $original = get_object_vars($raw[0]);
        $inputs = [];
        $answerId = null;
        $parameter = $answerInput = [];
        $baseUrl = '/api/v1/admin/projects/'.$scope[1].'/estimate-generation/sessions/'.$scope[2];
        $headers = $this->sourceHeaders($actor);
        foreach ([0, 1, 2, 3] as $phase) {
            [$session, $operationId] = $this->paidReceipt(RunEstimateComposer::ANSWER_PROMPT_CONTRACT, [], true,
                [$organization, $project, $actor, $session->fresh()], false,
                function (EstimateGenerationSession $current, string $operationId) use ($actor, $scope, $sources, $initialAnswer, $phase, $workSection, &$inputs): int {
                    $work = ['kind' => 'supplementary', 'candidate_id' => null, 'work_key' => 'custom.installation',
                        'name' => 'Монтаж неизвестной установки', 'work_section' => $workSection, 'unit' => 'count',
                        'derived_quantity_id' => null, 'source_fact_ids' => [$initialAnswer->id, $sources[0]->id],
                        'entity_id' => $sources[0]->entityId, 'technology_package_candidate' => null,
                        'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => [],
                        ...($phase === 0 ? ['technology_clarification' => ['question' => 'Какой способ монтажа предусмотрен для этой установки?',
                            'reason' => 'В текущем источнике не описан способ монтажа.']] : [])];
                    $history = [];
                    if ($phase >= 2) {
                        $wire = $this->parameterSdkWire($work, $history);
                    } else {
                    $wire = $this->createMock(RerankWireClient::class);
                    $wire->method('provider')->willReturn('timeweb');
                    $wire->expects(self::once())->method('call')->willReturnCallback(function (string $model, array $messages, array $options)
                        use ($scope, $operationId, $work, &$inputs): array {
                        $payload = json_decode($messages[1]['content'], true, 512, JSON_THROW_ON_ERROR)['project'];
                        $inputs[] = $payload;
                        self::assertSame([], array_values(array_filter($payload['facts'], static fn (array $fact): bool => str_starts_with($fact['type'], 'engineering_parameter_'))));
                        return $this->composerWireResponse($scope, $operationId, $options,
                            ['work_intents' => [$work], 'claim_candidates' => []], $model);
                    });
                    }
                    $composer = new RunEstimateComposer(app(AiRoleRunRepository::class),
                        new TimewebEstimateComposerModel($wire, app(EloquentAiUsageStore::class), app(AiPriceSnapshotResolver::class),
                            'openai/gpt-6-luna', 524288, 8192, 60), 'openai/gpt-6-luna', allowance: app(EstimateComposerAllowance::class));
                    [$claim, $result] = $this->naturalQuestionStages($actor, $current, $operationId, $composer, true);
                    if ($phase >= 2) {
                        self::assertCount(1, $history);
                        $payload = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
                        self::assertSame(['type' => 'json_object'], $payload['response_format']);
                        $inputs[] = json_decode($payload['messages'][1]['content'], true, flags: JSON_THROW_ON_ERROR)['project'];
                    }
                    DB::transaction(fn () => app(Pipeline\PublishValidatedDraft::class)->beforeComplete($claim, $result, new \DateTimeImmutable));
                    return (int) DB::table('estimate_generation_ai_role_runs')->where('organization_id', $scope[0])->where('session_id', $scope[2])
                        ->where('role', 'estimate_composer')->whereIn('physical_attempt_id', DB::table('estimate_generation_vision_physical_attempts')
                            ->where('credit_operation_id', $operationId)->select('attempt_id'))->sole()->id;
                });
            if ($phase === 0) {
                $this->useRealQuestionAuthorization();
                $card = array_values(array_filter($this->getJson($baseUrl.'/questions', $headers)->assertOk()->json('data.items'),
                    static fn (array $item): bool => ($item['source_locator']['kind'] ?? null) === 'composer_work_technology'))[0];
                self::assertCount(1, $card['parameter_options'] ?? []);
                $option = $card['parameter_options'][0];
                self::assertSame('102', $option['value']);
                $parameter = ['name' => 'Свободный зазор B', 'value' => '0.102', 'unit' => 'm', 'basis' => 'measurement',
                    'source_operand_ref' => $option['source_operand_ref'], 'condition' => 'minimum_length'];
                $answerInput = ['response' => 'other', 'other' => 'Трактовать выбранное число как проектный минимум для этой работы.',
                    'document_ids' => [$option['source']['document_id']], 'idempotency_key' => 'typed-native-answer-'.$session->id,
                    'expected_source_version' => $card['source_version'], 'answer_fingerprint' => $card['answer_fingerprint'], 'parameter' => $parameter];
                $answerUrl = $baseUrl.'/questions/'.$card['code'].'/answer';
                $this->postJson($answerUrl, [...$answerInput, 'parameter' => [...$parameter, 'source_operand_ref' => str_repeat('f', 64)]], $headers)->assertStatus(409);
                $this->postJson($answerUrl, [...$answerInput, 'document_ids' => []], $headers)->assertStatus(422);
                $this->postJson($answerUrl, [...$answerInput, 'document_ids' => [999999]], $headers)->assertStatus(409);
                $this->postJson($answerUrl, [...$answerInput, 'parameter' => [...$parameter, 'value' => 102.5]], $headers)->assertStatus(422);
                $this->postJson($answerUrl, [...$answerInput, 'target_entity_id' => $sources[1]->entityId], $headers)->assertStatus(422);
                $this->postJson($answerUrl, $answerInput, $headers)->assertOk();
                $this->postJson($answerUrl, $answerInput, $headers)->assertOk();
                $this->postJson($answerUrl, [...$answerInput, 'parameter' => [...$parameter, 'value' => '101']], $headers)->assertStatus(422);
                $receipt = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetClarificationAnswerReceipt::class)
                    ->handle($actor, $scope[1], $scope[2], $card['code'], array_intersect_key($answerInput,
                        array_flip(['idempotency_key', 'expected_source_version', 'answer_fingerprint', 'document_ids'])));
                self::assertSame('saved', $receipt['status']);
                self::assertSame(CanonicalPipelineJson::encode($parameter), CanonicalPipelineJson::encode($receipt['parameter']));
                self::assertSame('0', $receipt['cost_units']);
                $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
                $generic = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => str_starts_with($fact->type, 'engineering_parameter_')));
                self::assertCount(2, $generic);
                foreach ($generic as $fact) {
                    self::assertSame('unresolved', $fact->status);
                    self::assertSame('user_assumption', $fact->origin);
                    self::assertSame([], $fact->nativeNumericProofs);
                }
                $answer = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->type === 'evaluation_answer'
                    && is_array($fact->value) && isset($fact->value['parameter'])))[0];
                $answerId = $answer->id;
                self::assertSame('confirmed', $answer->status);
                self::assertSame($raw[0]->id, $answer->value['parameter']['association']['raw_fact_id']);
                self::assertCount(0, EstimateGenerationLearningExample::query()->where('generation_session_id', $scope[2])->get());
                $approved = (int) DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->value('approved_state_version');
                self::assertGreaterThan($approved, $answer->value['composer_context']['state_version']);
                self::assertSame(0, DB::transaction(fn () => app(EstimateGenerationLearningRecorder::class)->recordComposerSourceClaims(...[...$scope, $operationId])));
                self::assertSame($original, get_object_vars($models->factsByIds(...[...$scope, [$raw[0]->id]])[0]));
            }
            if ($phase === 2) {
                $this->useRealQuestionAuthorization();
                $counts = fn (): array => [DB::table('estimate_generation_ai_usage')->where('session_id', $scope[2])->count(),
                    DB::table('estimate_generation_ai_role_runs')->where('session_id', $scope[2])->count(),
                    DB::table('estimate_generation_credit_attempts')->where('session_id', $scope[2])->count(),
                    EstimateGenerationLearningExample::query()->where('generation_session_id', $scope[2])->count()];
                $beforeRead = $counts();
                $response = $this->getJson($baseUrl.'/questions', $headers)->assertOk();
                self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
                $card = array_values(array_filter($response->json('data.items'), static fn (array $item): bool => ($item['source_locator']['kind'] ?? null) === 'composer_work_technology'))[0];
                self::assertCount(1, $card['parameter_checks']);
                self::assertSame([], $card['source_checks']);
                self::assertTrue($card['parameter_checks'][0]['meets_minimum']);
                $source = $card['parameter_checks'][0]['source'];
                $viewer = $this->getJson($baseUrl.'/evidence-sources?'.http_build_query(['references' => [$source['reference']]]), $headers)
                    ->assertOk()->json('data.sources.0');
                self::assertSame('available', $viewer['status']);
                self::assertSame($source['document_id'], $viewer['target']['artifact_id']);
                self::assertStringContainsString($source['value_cell'], $viewer['target']['native_reference']);
                $reviewer = $rights->addMember(['ai-estimates' => ['estimate_generation.review']], ['admin.access']);
                $reviewer->organizations()->updateExistingPivot($scope[0], ['project_access_mode' => 'all_projects']);
                $reviewHeaders = $this->sourceHeaders($reviewer);
                $review = $this->getJson($baseUrl.'/questions', $reviewHeaders)->assertOk();
                $reviewCard = array_values(array_filter($review->json('data.items'), static fn (array $item): bool => ($item['source_locator']['kind'] ?? null) === 'composer_work_technology'))[0];
                self::assertSame($card['answer'], $reviewCard['answer']);
                self::assertSame([], $reviewCard['parameter_options']);
                self::assertSame([], $reviewCard['parameter_checks']);
                self::assertStringNotContainsString($source['reference'], $review->getContent());
                $this->postJson($baseUrl.'/questions/'.$card['code'].'/answer', [...$answerInput,
                    'idempotency_key' => 'typed-without-source-view', 'answer_fingerprint' => $card['answer_fingerprint']], $reviewHeaders)->assertForbidden();
                self::assertContains($this->getJson($baseUrl.'/questions', $this->sourceHeaders($rights->foreignOwner))->status(), [403, 404]);
                DB::beginTransaction();
                try {
                    $nested = $this->getJson($baseUrl.'/questions', $headers)->assertOk()->json('data.items');
                    $nested = array_values(array_filter($nested, static fn (array $item): bool => ($item['source_locator']['kind'] ?? null) === 'composer_work_technology'))[0];
                    self::assertSame([], $nested['parameter_options']);
                    self::assertSame([], $nested['parameter_checks']);
                    self::assertSame('off', DB::selectOne('SHOW transaction_read_only')->transaction_read_only);
                } finally {
                    DB::rollBack();
                }
                self::assertSame($beforeRead, $counts());
                $factory = function (?string $local = null) use ($session, $scope) {
                    $session->refresh();
                    return app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null,
                        $session->input_payload['selected_sections'], 'universal', $session->input_payload, $local]);
                };
                self::assertSame([], $factory('foreign-local')->workTechnologyContext);
                $document = EstimateGenerationDocument::query()->findOrFail($source['document_id']);
                $checksum = $document->checksum_sha256;
                try {
                    $document->update(['checksum_sha256' => str_repeat('f', 64)]);
                    $drifted = $factory();
                    self::assertArrayNotHasKey('component', $drifted->workTechnologyContext[0]['answer']['parameter'] ?? []);
                } finally {
                    $document->update(['checksum_sha256' => $checksum]);
                }
                self::assertArrayHasKey('component', $factory()->workTechnologyContext[0]['answer']['parameter']);
                $derivedIds = [$card['answer']['parameter']['observed_fact_id'], $card['answer']['parameter']['requirement_fact_id']];
                $history = fn () => DB::table('estimate_generation_project_model_assertions')->where('session_id', $scope[2])
                    ->whereIn('stable_key', $derivedIds)->orderBy('stable_key')->get()->toJson();
                $storedHistory = $history();
                DB::beginTransaction();
                try {
                    self::assertSame(1, app(EvidenceRepository::class)->invalidate(...[...$scope,
                        [(int) substr($source['reference'], 9)], 'parameter_source_invalidated_fixture']));
                    $staleSnapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
                    self::assertSame([], array_values(array_filter($staleSnapshot->facts,
                        static fn (Fact $fact): bool => str_starts_with($fact->type, 'engineering_parameter_'))));
                    self::assertSame([], $models->factsByIds(...[...$scope, $derivedIds]));
                    $invalidatedCard = array_values(array_filter($this->getJson($baseUrl.'/questions', $headers)->assertOk()->json('data.items'),
                        static fn (array $item): bool => ($item['source_locator']['kind'] ?? null) === 'composer_work_technology'))[0];
                    self::assertSame('other', $invalidatedCard['answer']['response']);
                    self::assertSame([], $invalidatedCard['parameter_checks']);
                    self::assertArrayNotHasKey('component', $factory()->workTechnologyContext[0]['answer']['parameter']);
                    self::assertSame(0, app(EstimateGenerationLearningRecorder::class)->recordComposerSourceClaims(...[...$scope, $operationId]));
                    self::assertSame($storedHistory, $history());
                } finally {
                    DB::rollBack();
                }
                $reanswer = [...$answerInput, 'idempotency_key' => 'typed-below-minimum', 'answer_fingerprint' => $card['answer_fingerprint'],
                    'parameter' => [...$parameter, 'value' => '101', 'unit' => 'mm']];
                $this->postJson($baseUrl.'/questions/'.$card['code'].'/answer', $reanswer, $headers)->assertOk();
                $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
                $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($capture['snapshot']->facts, 'id')]);
                $components = app(ComposerSourceClaimValidationService::class)->parameterComponents($scope, $capture['snapshot'], $decisions, $session->fresh()->input_payload);
                self::assertCount(1, $components);
                self::assertFalse(array_values($components)[0]['component']['meets_minimum']);
                self::assertArrayNotHasKey('component', $factory()->workTechnologyContext[0]['answer']['parameter']);
                $newCard = array_values(array_filter($this->getJson($baseUrl.'/questions', $headers)->assertOk()->json('data.items'),
                    static fn (array $item): bool => ($item['source_locator']['kind'] ?? null) === 'composer_work_technology'))[0];
                $this->postJson($baseUrl.'/questions/'.$newCard['code'].'/answer', ['response' => 'revoke',
                    'idempotency_key' => 'typed-revoke', 'expected_source_version' => $newCard['source_version'],
                    'answer_fingerprint' => $newCard['answer_fingerprint']], $headers)->assertOk();
                self::assertArrayNotHasKey('parameter', $factory()->workTechnologyContext[0]['answer']);
            }
        }
        $row = EstimateGenerationLearningExample::query()->where('generation_session_id', $scope[2])->sole();
        self::assertSame('estimate_session', $row->source_entity_type);
        self::assertSame('proposed', $row->decision_status);
        self::assertFalse($row->is_positive);
        self::assertNull($row->accepted_at);
        self::assertFalse(EstimateGenerationLearningSourceTrustPolicy::isIndexable($row));
        self::assertArrayNotHasKey('component', $inputs[1]['work_technology_context'][0]['answer']['parameter']);
        $component = $inputs[2]['work_technology_context'][0]['answer']['parameter']['component'];
        self::assertSame((int) $row->id, $component['learning_example_id']);
        self::assertSame($answerId, $component['source_answer_fact_id']);
        self::assertTrue($component['meets_minimum']);
        self::assertFalse($component['numeric_inputs_verified']);
        self::assertSame('unresolved', $component['engineering_applicability']);
        self::assertSame([], $inputs[2]['source_claim_refs'] ?? []);
        self::assertSame('revoke', $inputs[3]['work_technology_context'][0]['answer']['response']);
        self::assertArrayNotHasKey('parameter', $inputs[3]['work_technology_context'][0]['answer']);
        self::assertSame($original, get_object_vars($models->factsByIds(...[...$scope, [$raw[0]->id]])[0]));
        self::assertSame(4, DB::table('estimate_generation_ai_role_runs')->where('organization_id', $scope[0])->where('role', 'estimate_auditor')->count());
    }

    private function useRealQuestionAuthorization(): void
    {
        foreach ([\App\Domain\Authorization\Services\AuthorizationService::class,
            \App\Services\Entitlements\OrganizationEntitlementService::class,
            \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization::class,
            \App\BusinessModules\Addons\EstimateGeneration\Questions\AnswerEstimateClarification::class,
            \App\BusinessModules\Addons\EstimateGeneration\Questions\ListEstimateClarifications::class,
            UserEvaluationChoiceWriter::class, EstimateGenerationQuestionController::class] as $service) {
            $this->app->forgetInstance($service);
        }
    }

    private function parameterSdkWire(array $work, array &$history): \App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebRerankWireClient
    {
        config(['ai-assistant.llm.timeweb.api_key' => 'fixture-not-a-real-key',
            'ai-assistant.llm.timeweb.base_uri' => 'https://timeweb.example.test/v1']);
        $body = ['id' => 'chatcmpl-parameter-fixture', 'object' => 'chat.completion', 'created' => 1,
            'model' => 'openai/gpt-6-luna', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant',
                'content' => json_encode(['work_intents' => [$work], 'claim_candidates' => []], JSON_THROW_ON_ERROR)],
                'finish_reason' => 'stop', 'logprobs' => null]],
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 100, 'total_tokens' => 1100,
                'prompt_tokens_details' => ['cached_tokens' => 0], 'completion_tokens_details' => ['reasoning_tokens' => 0]]];
        $handler = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR))]));
        $handler->push(\GuzzleHttp\Middleware::history($history));

        return new \App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebRerankWireClient(
            new \App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebChatCompletionPayloadFactory,
            new \App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostGuard(DB::connection(),
                app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization::class),
                app(EstimatorCreditAttemptAdmission::class)),
            new \App\BusinessModules\Addons\EstimateGeneration\Analysis\DurableAiPhysicalResponseStore(DB::connection()),
            httpClient: new \GuzzleHttp\Client(['handler' => $handler]));
    }

    public function test_identical_native_uploads_keep_distinct_document_provenance_in_the_project_model(): void
    {
        [, , , $session] = $this->fixture();
        $this->publishBinarySource($session, duplicate: true);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $documents = EstimateGenerationDocument::query()->where('organization_id', $scope[0])->where('project_id', $scope[1])->where('session_id', $scope[2])->orderBy('id')->get();
        self::assertCount(2, $documents);
        self::assertSame($documents[0]->source_version, $documents[1]->source_version);
        self::assertSame($documents[0]->checksum_sha256, $documents[1]->checksum_sha256);
        $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $facts = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->type === 'equipment_specification'));
        self::assertCount(4, $facts);
        self::assertCount(4, array_unique(array_column($facts, 'entityId')));
        $proofs = array_column($snapshot->evidence, null, 'id');
        $counts = [];
        foreach ($facts as $fact) {
            self::assertCount(1, $fact->evidenceIds);
            $reference = $proofs[$fact->evidenceIds[0]]->sourceReference;
            self::assertContains($reference, ['document:'.$documents[0]->id, 'document:'.$documents[1]->id]);
            $counts[$reference] = ($counts[$reference] ?? 0) + 1;
        }
        self::assertSame(2, $counts['document:'.$documents[0]->id]);
        self::assertSame(2, $counts['document:'.$documents[1]->id]);
    }

    #[DataProvider('workSourceFinalModes')]
    public function test_active_work_answer_uses_independent_attached_native_literal_through_terminal_learning_and_next_composer(bool $replaceWork): void
    {
        Queue::fake();
        config(['cache.default' => 'array', 'web_auth.origins.admin' => ['https://admin.fixture.test']]);
        $rights = AssistantRealAuthorizationFixture::create(['working-entry']);
        $rights->memberRole->update(['module_permissions' => ['ai-estimates' => ['estimate_generation.create', 'estimate_generation.generate',
            'estimate_generation.view', 'estimate_generation.review', 'estimate_generation.upload_documents']], 'system_permissions' => ['admin.access']]);
        $rights->member->organizations()->updateExistingPivot($rights->organization->id, ['project_access_mode' => 'all_projects']);
        [$actor, $session, $initialAnswer, $sources] = $this->answeredSourceFixture(naturalInitial: true, targetMode: 'selected',
            selectedSections: ['equipment', 'finishing'], realActor: $rights->member);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $organization = Organization::query()->findOrFail($scope[0]);
        $project = Project::query()->findOrFail($scope[1]);
        $document = EstimateGenerationDocument::query()->where('organization_id', $scope[0])
            ->where('project_id', $scope[1])->where('session_id', $scope[2])->sole();
        $priorCalls = DB::table('estimate_generation_ai_usage')->where('organization_id', $scope[0])->count();
        $workAnswerId = null;
        $inputs = [];
        foreach ([0, 1, 2] as $phase) {
            [$session, $operationId] = $this->paidReceipt(RunEstimateComposer::ANSWER_PROMPT_CONTRACT, [], true,
                [$organization, $project, $actor, $session->fresh()], false,
                function (EstimateGenerationSession $current, string $operationId) use ($actor, $scope, $sources, $initialAnswer, $phase, $replaceWork, &$workAnswerId, &$inputs): int {
                    $wire = $this->createMock(RerankWireClient::class);
                    $wire->method('provider')->willReturn('timeweb');
                    $wire->expects(self::once())->method('call')->willReturnCallback(function (string $model, array $messages, array $options) use ($operationId, $scope, $sources, $initialAnswer, $phase, $replaceWork, &$workAnswerId, &$inputs): array {
                        $payload = json_decode($messages[1]['content'], true, 512, JSON_THROW_ON_ERROR)['project'];
                        $inputs[] = $payload;
                        if ($phase > 0) {
                            self::assertSame(RunEstimateComposer::ANSWER_PROMPT_CONTRACT, $payload['contract_version']);
                            self::assertContains($workAnswerId, $payload['claim_answer_fact_ids']);
                            self::assertNotContains($workAnswerId, array_column($payload['facts'], 'id'));
                            self::assertSame('user_assumption', $payload['work_technology_context'][0]['answer']['trust']);
                            self::assertFalse($payload['work_technology_context'][0]['answer']['numeric_parameters_confirmed']);
                            self::assertCount($phase === 2 ? 1 : 0, $payload['source_claim_refs'] ?? []);
                        }
                        $work = ['kind' => 'supplementary', 'candidate_id' => null, 'work_key' => 'custom.installation',
                            'name' => 'Монтаж неизвестной установки', 'work_section' => 'equipment', 'unit' => 'count',
                            'derived_quantity_id' => null, 'source_fact_ids' => [$initialAnswer->id, $sources[0]->id],
                            'entity_id' => $sources[0]->entityId, 'technology_package_candidate' => null,
                            'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => [],
                            ...($phase === 0 ? ['technology_clarification' => ['question' => 'Какой способ монтажа предусмотрен для этой установки?',
                                'reason' => 'В текущем источнике не описан способ монтажа.']] : [])];
                        $candidate = ['source_answer_fact_id' => $workAnswerId, 'entity_id' => $sources[0]->entityId,
                            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => $sources[0]->value],
                            'unit' => null, 'document_fact_ids' => [$sources[0]->id]];
                        $second = [...$work, 'work_key' => 'custom.surface_preparation', 'name' => 'Подготовка поверхности для размещения установки', 'work_section' => 'finishing'];
                        unset($second['technology_clarification']);
                        if ($replaceWork && $phase === 2) {
                            $work['work_key'] = 'custom.replaced_installation';
                            $work['name'] = 'Иная работа по размещению установки';
                        }
                        return $this->composerWireResponse($scope, $operationId, $options,
                            ['work_intents' => [$work, $second], 'claim_candidates' => $phase === 1 ? [$candidate] : []], $model);
                    });
                    $composer = new RunEstimateComposer(app(AiRoleRunRepository::class),
                        new TimewebEstimateComposerModel($wire, app(EloquentAiUsageStore::class), app(AiPriceSnapshotResolver::class),
                            'openai/gpt-6-luna', 524288, 8192, 60), 'openai/gpt-6-luna', allowance: app(EstimateComposerAllowance::class));
                    [$claim, $result] = $this->naturalQuestionStages($actor, $current, $operationId, $composer, true);
                    DB::transaction(fn () => app(Pipeline\PublishValidatedDraft::class)->beforeComplete($claim, $result, new \DateTimeImmutable));
                    return (int) DB::table('estimate_generation_ai_role_runs')->where('organization_id', $scope[0])
                        ->where('project_id', $scope[1])->where('session_id', $scope[2])->where('status', 'completed')->where('role', 'estimate_composer')
                        ->whereIn('physical_attempt_id', DB::table('estimate_generation_vision_physical_attempts')
                            ->where('credit_operation_id', $operationId)->select('attempt_id'))->sole()->id;
                });
            $session->refresh();
            if ($phase === 1) {
                $capture = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001]);
                $decisions = app(ProjectModelRepository::class)->decisionsForSelectedFacts(...[...$scope, array_column($capture['snapshot']->facts, 'id')]);
                $candidate = ['source_answer_fact_id' => $workAnswerId, 'entity_id' => $sources[0]->entityId,
                    'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => $sources[0]->value],
                    'unit' => null, 'document_fact_ids' => [$sources[0]->id]];
                $checked = [];
                self::assertNotNull(app(ComposerSourceClaimValidationService::class)->capture($scope, $capture['snapshot'], $decisions,
                    $session->input_payload, $candidate, GeometryInventory::currentDocumentVersions($capture['snapshot'], false), $checked,
                    RunEstimateComposer::ANSWER_PROMPT_CONTRACT), 'Current work/native predicate must remain valid after publication.');
                self::assertCount(1, app(EstimateGenerationLearningRecorder::class)->sourceClaimCandidatesForOperation($session, $operationId));
                self::assertSame(1, EstimateGenerationLearningExample::query()->where('generation_session_id', $scope[2])->count());
            }
            if ($phase === 0) {
                $request = Request::create('/', 'GET');
                $request->setUserResolver(static fn (): User => $actor);
                $cards = app(EstimateGenerationQuestionController::class)->index($request, $project, $session)->getData(true)['data']['items'];
                $cards = array_values(array_filter($cards, static fn (array $card): bool => ($card['source_locator']['kind'] ?? null) === 'composer_work_technology'));
                self::assertCount(1, $cards);
                self::assertSame(200, $this->answerComposerCard($actor, $project, $session, $cards[0], ['other' => 'Использовать приложенное основание как допущение для этой работы.',
                    'document_ids' => [(int) $document->id]]));
                $capture = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001]);
                $answers = array_values(array_filter($capture['snapshot']->facts, static fn (Fact $fact): bool => $fact->type === 'evaluation_answer'
                    && is_array($fact->value) && ($fact->value['question_key'] ?? null) === $cards[0]['code']));
                self::assertCount(1, $answers);
                $workAnswerId = $answers[0]->id;
            }
        }
        $row = EstimateGenerationLearningExample::query()->where('generation_session_id', $scope[2])->sole();
        self::assertSame($workAnswerId, $row->context_payload['candidate']['source_answer_fact_id']);
        self::assertFalse($row->context_payload['source_claim_validation']['numeric_parameters_confirmed']);
        self::assertSame('passed', $row->context_payload['source_claim_validation']['source_correspondence']);
        self::assertSame('unresolved', $row->context_payload['source_claim_validation']['engineering_applicability']);
        self::assertSame('proposed', $row->decision_status);
        self::assertFalse($row->is_positive);
        self::assertNull($row->accepted_at);
        self::assertNull($row->estimate_norm_id);
        self::assertFalse($row->context_payload['training_allowed']);
        self::assertFalse(EstimateGenerationLearningSourceTrustPolicy::isIndexable($row));
        self::assertSame($priorCalls + 6, DB::table('estimate_generation_ai_usage')->where('organization_id', $scope[0])->count());
        self::assertSame(3, DB::table('estimate_generation_ai_role_runs')->where('organization_id', $scope[0])->where('role', 'estimate_auditor')->count());
        self::assertCount(3, $inputs);
        self::assertSame([$sources[0]->id], $inputs[2]['source_claim_refs'][0]['document_fact_ids']);
        $models = app(ProjectModelRepository::class);
        $sourceBefore = get_object_vars($models->fact(...[...$scope, $sources[0]->id]));
        $currentInput = function (?string $local = null) use ($scope, $session) {
            $session->refresh();

            return app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null,
                $session->input_payload['selected_sections'], 'universal', $session->input_payload, $local]);
        };
        AuthorizationContext::getProjectContext($scope[1], $scope[0]);
        foreach ([\App\Domain\Authorization\Services\AuthorizationService::class,
            \App\Services\Entitlements\OrganizationEntitlementService::class,
            \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization::class,
            \App\BusinessModules\Addons\EstimateGeneration\Questions\ListEstimateClarifications::class,
            EstimateGenerationQuestionController::class] as $service) {
            $this->app->forgetInstance($service);
        }
        $headers = $this->sourceHeaders($actor);
        $url = '/api/v1/admin/projects/'.$scope[1].'/estimate-generation/sessions/'.$scope[2];
        if ($replaceWork) {
            self::assertSame([], $currentInput()->sourceClaimReferences);
            self::assertSame($sourceBefore, get_object_vars($models->fact(...[...$scope, $sources[0]->id])));
            self::assertSame(1, EstimateGenerationLearningExample::query()->where('generation_session_id', $scope[2])->count());

            foreach ($this->getJson($url.'/questions', $headers)->assertOk()->json('data.items') as $item) {
                self::assertSame([], $item['source_checks'] ?? []);
            }
            return;
        }
        $counts = fn (): array => [DB::table('estimate_generation_ai_usage')->where('session_id', $scope[2])->count(),
            DB::table('estimate_generation_ai_role_runs')->where('session_id', $scope[2])->count(),
            DB::table('estimate_generation_credit_attempts')->where('session_id', $scope[2])->count(),
            DB::table('estimate_generation_project_model_assertions')->where('session_id', $scope[2])->count(),
            DB::table('estimate_generation_project_model_corrections')->where('session_id', $scope[2])->count(),
            DB::table('estimate_generation_evidence')->where('session_id', $scope[2])->count(),
            EstimateGenerationLearningExample::query()->where('generation_session_id', $scope[2])->count()];
        $beforeRead = $counts();
        $response = $this->getJson($url.'/questions', $headers)->assertOk();
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $card = array_values(array_filter($response->json('data.items'), static fn (array $item): bool => ($item['source_locator']['kind'] ?? null) === 'composer_work_technology'))[0];
        $check = $card['source_checks'][0] ?? null;
        self::assertSame(CanonicalPipelineJson::encode(['source_answer_fact_id' => $workAnswerId, 'entity_id' => $sources[0]->entityId,
            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => $sources[0]->value],
            'source' => ['reference' => $row->context_payload['source_claim_validation']['identity']['source']['evidence_id'],
                'document_id' => (int) $document->id, 'source_version' => $document->source_version,
                'cell' => $row->context_payload['source_claim_validation']['identity']['source']['literal_witness']['cell']],
            'interpretation_status' => 'proposed', 'source_correspondence' => 'passed',
            'engineering_applicability' => 'unresolved', 'numeric_parameters_confirmed' => false]), CanonicalPipelineJson::encode($check));
        self::assertSame('user_assumption', $card['answer']['trust']);
        self::assertCount(10, $currentInput()->sourceClaimReferences[0]);
        $originalStatus = $session->status;
        try {
            foreach (['generating', 'ready_to_apply'] as $status) {
                $session->update(['status' => $status]);
                $readOnly = $this->getJson($url.'/questions', $headers)->assertOk()->json('data.items');
                $readOnlyCard = array_values(array_filter($readOnly, static fn (array $item): bool => ($item['source_locator']['kind'] ?? null) === 'composer_work_technology'))[0];
                self::assertSame($check, $readOnlyCard['source_checks'][0]);
                self::assertSame([], $readOnlyCard['actions']);
            }
        } finally {
            $session->update(['status' => $originalStatus]);
        }
        $source = $this->getJson($url.'/evidence-sources?'.http_build_query(['references' => [$check['source']['reference']]]), $headers)
            ->assertOk()->json('data.sources.0');
        self::assertSame('available', $source['status']);
        self::assertSame((int) $document->id, $source['target']['artifact_id']);
        self::assertStringContainsString($check['source']['cell'], $source['target']['native_reference']);
        $reviewer = $rights->addMember(['ai-estimates' => ['estimate_generation.review']], ['admin.access']);
        $reviewer->organizations()->updateExistingPivot($scope[0], ['project_access_mode' => 'all_projects']);
        $reviewHeaders = $this->sourceHeaders($reviewer);
        $review = $this->getJson($url.'/questions', $reviewHeaders)->assertOk();
        $reviewCard = array_values(array_filter($review->json('data.items'), static fn (array $item): bool => ($item['source_locator']['kind'] ?? null) === 'composer_work_technology'))[0];
        self::assertSame($card['answer'], $reviewCard['answer']);
        self::assertSame([], $reviewCard['source_checks']);
        self::assertStringNotContainsString($check['source']['reference'], $review->getContent());
        self::assertStringNotContainsString($sources[0]->value, json_encode($review->json(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->getJson($url.'/evidence-sources?'.http_build_query(['references' => [$check['source']['reference']]]), $reviewHeaders)->assertForbidden();
        self::assertContains($this->getJson($url.'/questions', $this->sourceHeaders($rights->foreignOwner))->status(), [403, 404]);
        DB::beginTransaction();
        try {
            $nested = $this->getJson($url.'/questions', $headers)->assertOk()->json('data.items');
            self::assertSame([], array_values(array_filter($nested, static fn (array $item): bool => ($item['source_locator']['kind'] ?? null) === 'composer_work_technology'))[0]['source_checks']);
            self::assertSame('off', DB::selectOne('SHOW transaction_read_only')->transaction_read_only);
        } finally {
            DB::rollBack();
        }
        self::assertSame($beforeRead, $counts());
        $originalDocument = ['checksum_sha256' => $document->checksum_sha256, 'source_version' => $document->source_version];
        $raced = false;
        config(['database.connections.source_check_race' => DB::connection()->getConfig()]);
        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$raced, $scope, $document): void {
            if ($raced || $query->connectionName === 'source_check_race' || $query->connection->transactionLevel() !== 1
                || ! str_contains($query->sql, 'estimate_generation_project_model_assertions')) {
                return;
            }
            $raced = true;
            DB::connection('source_check_race')->table('estimate_generation_documents')->where('organization_id', $scope[0])
                ->where('project_id', $scope[1])->where('session_id', $scope[2])->where('id', $document->id)
                ->update(['checksum_sha256' => str_repeat('e', 64), 'source_version' => 'sha256:'.str_repeat('e', 64)]);
        });
        try {
            $during = $this->getJson($url.'/questions', $headers)->assertOk()->json('data.items');
            self::assertTrue($raced);
            $duringCard = array_values(array_filter($during, static fn (array $item): bool => ($item['source_locator']['kind'] ?? null) === 'composer_work_technology'))[0];
            self::assertSame($check, $duringCard['source_checks'][0]);
            self::assertSame('sha256:'.str_repeat('e', 64), $document->fresh()->source_version);
            $after = $this->getJson($url.'/questions', $headers)->assertOk()->json('data.items');
            foreach ($after as $item) {
                self::assertSame([], $item['source_checks'] ?? []);
            }
            self::assertSame($beforeRead, $counts());
        } finally {
            $document->refresh()->update($originalDocument);
            DB::purge('source_check_race');
        }
        self::assertCount(1, $currentInput('ai-composer-supplementary:equipment')->sourceClaimReferences);
        self::assertSame([], $currentInput('ai-composer-supplementary:finishing')->sourceClaimReferences);
        self::assertSame([], $currentInput('missing-local')->sourceClaimReferences);
        $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
        $snapshot = $capture['snapshot'];
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        $candidate = $row->context_payload['candidate'];
        $predicate = app(ComposerSourceClaimValidationService::class);
        $anchors = GeometryInventory::currentDocumentVersions($snapshot, false);
        foreach ([array_replace($candidate, ['value' => ['type' => 'string', 'data' => 'Иной буквальный текст']]),
            array_replace($candidate, ['entity_id' => $sources[1]->entityId, 'document_fact_ids' => [$sources[1]->id]])] as $invalid) {
            $checked = [];
            self::assertNull($predicate->capture($scope, $snapshot, $decisions, $session->input_payload, $invalid, $anchors, $checked, RunEstimateComposer::ANSWER_PROMPT_CONTRACT));
        }
        foreach (['estimate-composer:answers:v4', 'estimate-composer:answers:v999'] as $unsupported) {
            $checked = [];
            self::assertNull($predicate->capture($scope, $snapshot, $decisions, $session->input_payload, $candidate, $anchors, $checked, $unsupported));
        }
        foreach ([[$scope[0] + 1, $scope[1], $scope[2]], [$scope[0], $scope[1], $scope[2] + 1]] as $foreign) {
            $checked = [];
            self::assertNull($predicate->capture($foreign, $snapshot, $decisions, $session->input_payload, $candidate, $anchors, $checked, RunEstimateComposer::ANSWER_PROMPT_CONTRACT));
        }
        $extra = new Fact(...[...get_object_vars($sources[0]), 'id' => 'fact:ambiguous-source']);
        $ambiguous = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot($snapshot->entities,
            [...$snapshot->facts, $extra], $snapshot->evidence, []);
        $checked = [];
        self::assertNull($predicate->capture($scope, $ambiguous, $decisions, $session->input_payload, $candidate, $anchors, $checked, RunEstimateComposer::ANSWER_PROMPT_CONTRACT));
        foreach (['fingerprint', 'nativeReference'] as $field) {
            $proofs = array_map(static fn ($proof) => ! in_array($proof->id, $sources[0]->evidenceIds, true) ? $proof
                : new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence(...[...get_object_vars($proof),
                    $field => $field === 'fingerprint' ? str_repeat('f', 64) : 'xlsx:sheet:Оборудование!Z999']), $snapshot->evidence);
            $changed = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot($snapshot->entities, $snapshot->facts, $proofs, []);
            $checked = [];
            self::assertNull($predicate->capture($scope, $changed, $decisions, $session->input_payload, $candidate, $anchors, $checked, RunEstimateComposer::ANSWER_PROMPT_CONTRACT));
        }
        foreach (['reanswer', 'not_attached', 'revoke', 'ignored', 'checksum', 'excluded'] as $drift) {
            self::assertCount(1, $currentInput()->sourceClaimReferences);
            DB::beginTransaction();
            try {
                if (in_array($drift, ['reanswer', 'not_attached', 'revoke'], true)) {
                    $request = Request::create('/', 'GET');
                    $request->setUserResolver(static fn (): User => $actor);
                    $cards = app(EstimateGenerationQuestionController::class)->index($request, $project, $session->fresh())->getData(true)['data']['items'];
                    $card = array_values(array_filter($cards, static fn (array $card): bool => ($card['source_locator']['kind'] ?? null) === 'composer_work_technology'))[0];
                    $override = $drift === 'revoke' ? ['response' => 'revoke', 'other' => null]
                        : ['other' => 'Новое независимое допущение пользователя.', 'document_ids' => $drift === 'not_attached' ? [] : [(int) $document->id]];
                    self::assertSame(200, $this->answerComposerCard($actor, $project, $session->fresh(), $card, $override));
                } elseif ($drift === 'ignored') {
                    app(Documents\IgnoreEstimateGenerationDocument::class)->handle($session->fresh(), $document,
                        (int) $session->state_version, 'Источник исключён из текущего проекта.');
                } elseif ($drift === 'checksum') {
                    $document->update(['checksum_sha256' => str_repeat('f', 64), 'source_version' => 'sha256:'.str_repeat('f', 64)]);
                } else {
                    $items = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationWorkScope)->items($session->draft_payload);
                    $selected = array_keys(array_filter($items, static fn (array $item): bool => $item['binding']['work_key'] === 'custom.surface_preparation'));
                    self::assertCount(1, $selected);
                    app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScopeSelection::class)->handle($actor, $project->id, $session->id,
                        ['request_id' => (string) Str::uuid(), 'state_version' => $session->state_version,
                            'selected_sections' => $session->input_payload['selected_sections'], 'profile_id' => 'construction',
                            'construction_type' => 'new_construction', 'price_policy' => 'normative',
                            'artifact_hash' => $session->draft_payload['artifact_hash'], 'selected_work_item_keys' => $selected,
                            'scope_reason' => 'Для текущего состава выбрана только подготовка поверхности.']);
                }
                self::assertSame([], $currentInput()->sourceClaimReferences, $drift);
                if ($drift === 'excluded') {
                    $excluded = $this->getJson($url.'/questions', $headers)->assertOk()->json('data.items');
                    self::assertNotContains($card['code'], array_column($excluded, 'code'));
                }
                self::assertSame($sourceBefore, get_object_vars($models->fact(...[...$scope, $sources[0]->id])));
                self::assertSame(1, EstimateGenerationLearningExample::query()->where('generation_session_id', $scope[2])->count());
            } finally {
                DB::rollBack();
                $session->refresh();
                $document->refresh();
            }
        }
        $active = $this->getJson($url.'/questions', $headers)->assertOk()->json('data.items');
        $activeCard = array_values(array_filter($active, static fn (array $item): bool => ($item['source_locator']['kind'] ?? null) === 'composer_work_technology'))[0];
        self::assertCount(1, $activeCard['source_checks']);
        self::assertSame(200, $this->answerComposerCard($actor, $project, $session->fresh(), $activeCard, ['response' => 'revoke', 'other' => null]));
        $revoked = $this->getJson($url.'/questions', $headers)->assertOk()->json('data.items');
        $revokedCard = array_values(array_filter($revoked, static fn (array $item): bool => ($item['source_locator']['kind'] ?? null) === 'composer_work_technology'))[0];
        self::assertSame('revoked', $revokedCard['status']);
        self::assertSame([], $revokedCard['source_checks']);
        self::assertSame($sourceBefore, get_object_vars($models->fact(...[...$scope, $sources[0]->id])));
        self::assertSame($priorCalls + 6, DB::table('estimate_generation_ai_usage')->where('organization_id', $scope[0])->count());
        Http::assertNothingSent();
    }

    public static function workSourceFinalModes(): iterable
    {
        yield 'current work and inactive-source fences' => [false];
        yield 'replacement does not inherit dependent reference' => [true];
    }
    #[DataProvider('initialQuestionModes')]
    public function test_actual_composer_claim_receipt_reaches_terminal_learning_and_the_next_explicit_composer_wire(bool $naturalInitial, ?string $uploadMoment = null, bool $targetWork = false, string $targetMode = 'unique'): void
    {
        Queue::fake();
        [$actor, $session, $answer, $documents] = $this->answeredSourceFixture(naturalInitial: $naturalInitial, uploadMoment: $uploadMoment, targetMode: $targetMode);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $priorCalls = ($naturalInitial ? 1 : 0) + ($uploadMoment === 'before_answer' ? 1 : 0);
        self::assertSame($priorCalls, DB::table('estimate_generation_ai_usage')->where('organization_id', $scope[0])->count());
        $models = app(ProjectModelRepository::class);
        $originals = array_map(static fn ($fact): array => get_object_vars($models->fact(...[...$scope, $fact->id])), [$answer, ...$documents]);
        $candidate = ['source_answer_fact_id' => $answer->id, 'entity_id' => $documents[0]->entityId,
            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => 'Монтаж стендов'],
            'unit' => null, 'document_fact_ids' => [$documents[0]->id]];
        $organization = Organization::query()->findOrFail($scope[0]);
        $project = Project::query()->findOrFail($scope[1]);
        $inputs = $operationIds = $runIds = [];
        foreach ([0, 1] as $phase) {
            $terminal = null;
            [$session, $operationId, $runId] = $this->paidReceipt(RunEstimateComposer::ANSWER_PROMPT_CONTRACT, [], true,
                [$organization, $project, $actor, $session->fresh()], false,
                function (EstimateGenerationSession $current, string $operationId) use ($actor, $scope, $answer, $documents, $candidate, $phase, $targetWork, $uploadMoment, &$inputs, &$terminal): int {
                    $terminal = $this->terminalFixture($actor, $current, $operationId);
                    $current = $current->fresh();
                    $input = app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [],
                        new AiSessionWireScope((int) $current->state_version, $operationId, $operationId), ['equipment'], 'universal', $current->input_payload]);
                    $inputs[] = $input;
                    self::assertSame(RunEstimateComposer::ANSWER_PROMPT_CONTRACT, $input->contractVersion);
                    self::assertSame([$answer->id], $input->claimAnswerFactIds);
                    self::assertCount($phase, $input->sourceClaimReferences);
                    self::assertSame([], $input->derivedQuantities);
                    $facts = array_column($input->facts, null, 'id');
                    self::assertNotSame($facts[$answer->id]['context_entity_id'], $documents[0]->entityId);
                    self::assertSame('equipment', $facts[$documents[0]->id]['entity_type']);
                    $wire = $this->createMock(RerankWireClient::class);
                    $wire->method('provider')->willReturn('timeweb');
                    $wire->expects(self::once())->method('call')->willReturnCallback(function (string $model, array $messages, array $options) use ($input, $scope, $answer, $documents, $candidate, $operationId, $phase, $targetWork): array {
                        $payload = json_decode($messages[1]['content'], true, 512, JSON_THROW_ON_ERROR)['project'];
                        self::assertSame($input->canonicalPayload(), $payload);
                        self::assertSame([$answer->id], $payload['claim_answer_fact_ids']);
                        $answerFact = array_column($payload['facts'], null, 'id')[$answer->id];
                        if ($answerFact['related_entity_ids'] !== []) {
                            self::assertStringContainsString('или явно переданных сервером related_entity_ids этого ответа', $messages[0]['content']);
                            self::assertStringContainsString('Для related_entity_ids допускается только предварительный состав без количества, единицы, нормы и цены.', $messages[0]['content']);
                        } else {
                            self::assertStringNotContainsString('related_entity_ids', $messages[0]['content']);
                        }
                        if ($phase === 0) {
                            self::assertArrayNotHasKey('source_claim_refs', $payload);
                        } else {
                            self::assertCount(1, $payload['source_claim_refs']);
                            $reference = $payload['source_claim_refs'][0];
                            self::assertCount(6, $reference);
                            self::assertSame('equipment_specification', $reference['fact_type']);
                            self::assertSame((int) EstimateGenerationLearningExample::query()->where('organization_id', $scope[0])
                                ->where('project_id', $scope[1])->where('generation_session_id', $scope[2])->sole()->id, $reference['learning_example_id']);
                            self::assertSame($documents[0]->entityId, $reference['entity_id']);
                            self::assertSame([$documents[0]->id], $reference['document_fact_ids']);
                            self::assertSame(CanonicalPipelineJson::encode($candidate['value']), CanonicalPipelineJson::encode($reference['value']));
                            self::assertNull($reference['unit']);
                            self::assertArrayNotHasKey('source_answer_fact_id', $reference);
                            self::assertStringContainsString('engineering applicability остаётся unresolved', $messages[0]['content']);
                        }
                        $work = $targetWork && $phase === 0 ? [[
                            'kind' => 'supplementary', 'candidate_id' => null, 'work_key' => 'custom.installation',
                            'name' => 'Монтаж неизвестной установки', 'work_section' => 'equipment', 'unit' => 'count',
                            'derived_quantity_id' => null, 'source_fact_ids' => [$answer->id, $documents[0]->id],
                            'entity_id' => $documents[0]->entityId, 'technology_package_candidate' => null,
                            'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => [],
                        ]] : [];
                        return $this->composerWireResponse($scope, $operationId, $options,
                            ['work_intents' => $work, 'claim_candidates' => $phase === 0 ? [$candidate] : []], $model);
                    });
                    $composer = new RunEstimateComposer(app(AiRoleRunRepository::class),
                        new TimewebEstimateComposerModel($wire, app(EloquentAiUsageStore::class), app(AiPriceSnapshotResolver::class),
                            'openai/gpt-6-luna', 524288, 8192, 60), 'openai/gpt-6-luna', allowance: app(EstimateComposerAllowance::class));
                    $outcome = $composer->runDetailed($input);
                    $expectedWork = $targetWork && $phase === 0 && ($uploadMoment === 'before_answer' || isset($answer->value['target_entity_id']));
                    if ($targetWork && $phase === 0) {
                        $projection = (new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateCompositionProjector)
                            ->apply([], $outcome['intents'], $input->derivedQuantities, $input->facts);
                        if ($expectedWork) {
                            self::assertCount(1, $projection, implode(',', array_column($outcome['quarantined'], 'reason')));
                            self::assertCount(1, $outcome['intents']);
                            $row = $projection[0]['sections'][0]['work_items'][0];
                            self::assertSame($documents[0]->entityId, $row['metadata']['scope_entity_id']);
                            self::assertSame('Монтаж неизвестной установки', $row['name']);
                            foreach (['unit', 'quantity', 'quantity_formula', 'work_cost', 'materials_cost', 'labor_cost', 'machinery_cost', 'total_cost', 'price_source'] as $field) {
                                self::assertNull($row[$field]);
                            }
                            foreach (['materials', 'labor', 'machinery', 'other_resources', 'work_composition'] as $field) {
                                self::assertSame([], $row[$field]);
                            }
                            foreach (['quantity_evidence', 'quantity_basis', 'normative_rate_id', 'normative_rate_code'] as $field) {
                                self::assertArrayNotHasKey($field, $row);
                            }
                            self::assertTrue($row['skip_normative_matching']);
                            self::assertSame('quantity_unknown', $row['pricing_blocker']);
                        } else {
                            self::assertSame([], $outcome['intents']);
                            self::assertSame([], $projection);
                            self::assertSame('estimate_composer_work_definition_unverified', $outcome['quarantined'][0]['reason']);
                        }
                    } else {
                        self::assertSame([], $outcome['intents']);
                    }
                    self::assertSame($outcome, $composer->runDetailed($input));
                    $receipt = DB::table('estimate_generation_ai_role_runs')->where('organization_id', $scope[0])
                        ->where('project_id', $scope[1])->where('session_id', $scope[2])->where('input_fingerprint', $input->fingerprint())
                        ->where('status', 'completed')->sole();
                    $stored = json_decode($receipt->result_payload, true, 512, JSON_THROW_ON_ERROR);
                    self::assertSame(2, $stored['schema_version']);
                    self::assertSame(CanonicalPipelineJson::encode($phase === 0 ? [$candidate] : []), CanonicalPipelineJson::encode($stored['claim_candidates']));

                    return (int) $receipt->id;
                });
            self::assertNotNull($terminal);
            DB::transaction(fn () => app(Pipeline\PublishValidatedDraft::class)->beforeComplete($terminal[0], $terminal[1], new \DateTimeImmutable));
            $session = $session->fresh();
            self::assertSame('input_review_required', $session->status->value);
            self::assertSame('settled', DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->value('status'));
            self::assertSame(1, EstimateGenerationLearningExample::query()->where('generation_session_id', $scope[2])->count());
            $operationIds[] = $operationId;
            $runIds[] = $runId;
        }
        self::assertCount(2, array_unique($operationIds));
        self::assertCount(2, array_unique($runIds));
        $row = EstimateGenerationLearningExample::query()->where('generation_session_id', $scope[2])->sole();
        self::assertSame($runIds[0], (int) $row->source_entity_id);
        self::assertNull($row->norm_code);
        self::assertFalse($row->is_positive);
        self::assertNull($row->accepted_at);
        self::assertSame('proposed', $row->context_payload['source_claim_validation']['interpretation_status']);
        self::assertSame('passed', $row->context_payload['source_claim_validation']['source_correspondence']);
        self::assertSame('unresolved', $row->context_payload['source_claim_validation']['engineering_applicability']);
        self::assertSame($originals, array_map(static fn ($fact): array => get_object_vars($models->fact(...[...$scope, $fact->id])), [$answer, ...$documents]));
        self::assertSame($priorCalls + 2, DB::table('estimate_generation_ai_usage')->where('organization_id', $scope[0])->count());
        self::assertSame($priorCalls + 2, DB::table('estimate_generation_ai_role_runs')->where('organization_id', $scope[0])->count());
        self::assertSame($priorCalls + 2, DB::table('estimate_generation_credit_attempts')->where('organization_id', $scope[0])->count());
        self::assertArrayNotHasKey('source_claim_refs', $inputs[0]->canonicalPayload());
        self::assertArrayHasKey('source_claim_refs', $inputs[1]->canonicalPayload());
        if ($uploadMoment === null) {
            Queue::assertNothingPushed();
        } else {
            Queue::assertPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob::class, 1);
            Queue::assertPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationUnitJob::class, 1);
        }
        Http::assertNothingSent();
    }

    public static function initialQuestionModes(): array
    {
        return ['signed_fixture' => [false], 'natural_initial_question' => [true],
            'answer_then_document' => [true, 'after_answer'], 'document_then_answer' => [true, 'before_answer'],
            'unique_current_card_target' => [true, 'before_answer', true], 'late_document_target' => [true, 'after_answer', true],
            'no_frozen_card_target' => [false, null, true], 'two_current_card_targets' => [true, null, true, 'multiple'],
            'mixed_numeric_card_targets' => [true, null, true, 'mixed'], 'selected_current_card_target' => [true, null, true, 'selected']];
    }

    #[DataProvider('answerTargetModes')]
    public function test_answer_target_context_does_not_survive_ignoring_its_frozen_document(string $mode): void
    {
        Queue::fake();
        [$actor, $session, $answer, $documents] = $this->answeredSourceFixture(naturalInitial: true, targetMode: $mode);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $factory = app(EstimateComposerInputFactory::class);
        $input = $factory->capture(...[...$scope, [], [], [], null, ['equipment'], 'universal', $session->input_payload]);
        $captured = array_column($input->facts, null, 'id')[$answer->id];
        self::assertSame([$documents[0]->entityId], $captured['related_entity_ids']);
        self::assertSame($answer->entityId, $captured['entity_id']);
        self::assertNotSame($answer->entityId, $documents[0]->entityId);
        $models = app(ProjectModelRepository::class);
        $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        $changedProofs = array_map(static fn ($proof) => in_array($proof->id, $documents[0]->evidenceIds, true)
            ? new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence(...[...get_object_vars($proof), 'sourceType' => 'user_input']) : $proof, $snapshot->evidence);
        self::assertNull(ResolveCurrentEstimateClarification::composerAnswerContext($answer,
            new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot($snapshot->entities, $snapshot->facts, $changedProofs, []),
            $decisions, $session->input_payload));
        $missingProofs = array_values(array_filter($snapshot->evidence, static fn ($proof): bool => ! in_array($proof->id, $documents[0]->evidenceIds, true)));
        self::assertNull(ResolveCurrentEstimateClarification::composerAnswerContext($answer,
            new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot($snapshot->entities, $snapshot->facts, $missingProofs, []),
            $decisions, $session->input_payload));
        $document = EstimateGenerationDocument::query()->where('session_id', $session->id)->sole();
        app(Documents\IgnoreEstimateGenerationDocument::class)->handle($session->fresh(), $document,
            (int) $session->state_version, 'Документ больше не определяет контекст ответа.');
        $current = $factory->capture(...[...$scope, [], [], [], null, ['equipment'], 'universal', $session->fresh()->input_payload]);
        self::assertNotContains($answer->id, array_column($current->facts, 'id'));
        self::assertSame([], $current->claimAnswerFactIds);
        self::assertSame([], $current->sourceClaimReferences);
        self::assertSame($answer->value, app(ProjectModelRepository::class)->fact(...[...$scope, $answer->id])->value);
        self::assertSame(1, DB::table('estimate_generation_ai_usage')->where('organization_id', $scope[0])->count());
        Http::assertNothingSent();
    }

    public static function answerTargetModes(): array
    {
        return ['inferred' => ['unique'], 'selected' => ['selected']];
    }

    public function test_atomic_target_receipt_replay_and_fresh_question_for_the_other_object(): void
    {
        Queue::fake();
        [$actor, $session, $answer, $documents] = $this->answeredSourceFixture(naturalInitial: true, targetMode: 'selected', selectedSections: ['equipment', 'finishing']);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $project = Project::query()->findOrFail($scope[1]);
        $card = ['code' => $answer->value['question_key'], 'source_version' => $answer->sourceVersion,
            'answer_fingerprint' => $answer->value['question_fingerprint']];
        $request = ['idempotency_key' => 'selected-answer-'.$session->id, 'target_entity_id' => $documents[0]->entityId];
        $receiptInput = [...$request, 'expected_source_version' => $card['source_version'], 'answer_fingerprint' => $card['answer_fingerprint']];
        $receipts = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetClarificationAnswerReceipt::class);
        self::assertSame('saved', $receipts->handle($actor, $scope[1], $scope[2], $card['code'], $receiptInput)['status']);
        self::assertSame('unavailable', $receipts->handle($actor, $scope[1], $scope[2], $card['code'],
            [...$receiptInput, 'target_entity_id' => $documents[1]->entityId])['status']);
        self::assertSame(200, $this->answerComposerCard($actor, $project, $session->fresh(), $card, $request));
        self::assertSame(422, $this->answerComposerCard($actor, $project, $session->fresh(), $card,
            [...$request, 'target_entity_id' => $documents[1]->entityId]));
        foreach ([null, ''] as $invalid) {
            try {
                $this->answerComposerCard($actor, $project, $session->fresh(), $card, ['target_entity_id' => $invalid]);
                self::fail('An empty target must fail request validation.');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                self::assertArrayHasKey('target_entity_id', $exception->errors());
            }
        }
        $session = $this->publishNaturalQuestion($actor, $session->fresh(), [$documents[1], $documents[0]]);
        $get = Request::create('/', 'GET');
        $get->setUserResolver(static fn (): User => $actor);
        $items = app(EstimateGenerationQuestionController::class)->index($get, $project, $session)->getData(true)['data']['items'];
        self::assertCount(1, $items);
        self::assertNotSame($card['code'], $items[0]['code']);
        self::assertSame([$documents[1]->entityId], array_column($items[0]['target_options'], 'entity_id'));
        self::assertContains($this->answerComposerCard($actor, $project, $session, $card, ['target_entity_id' => $documents[0]->entityId]), [404, 409]);
        $answers = array_values(array_filter(app(ProjectModelRepository::class)->snapshot(...$scope)->facts,
            static fn (Fact $fact): bool => ($fact->value['kind'] ?? null) === 'composer_answer'));
        self::assertCount(1, $answers);
        self::assertSame(get_object_vars($answer), get_object_vars($answers[0]));
        self::assertSame(2, DB::table('estimate_generation_ai_usage')->where('organization_id', $scope[0])->count());
        Http::assertNothingSent();
    }

    public function test_source_info_request_fixture_real_answer_and_independent_binary_literal_support_only_the_exact_document_entity(): void
    {
        [$actor, $session, $answer, $documents] = $this->answeredSourceFixture();
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        $anchors = GeometryInventory::currentDocumentVersions($snapshot, false);
        $candidate = ['source_answer_fact_id' => $answer->id, 'entity_id' => $documents[0]->entityId,
            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => 'Монтаж стендов'],
            'unit' => null, 'document_fact_ids' => [$documents[0]->id]];
        $predicate = app(ComposerSourceClaimValidationService::class);
        $supported = $predicate->capture($scope, $snapshot, $decisions, $session->input_payload, $candidate, $anchors);
        self::assertNotNull($supported);
        self::assertSame('passed', $supported['source_correspondence']);
        self::assertSame('proposed', $supported['interpretation_status']);
        self::assertSame('unresolved', $supported['engineering_applicability']);
        self::assertSame('user_assumption', $answer->origin);
        self::assertSame([], $answer->nativeNumericProofs);
        self::assertNotSame($answer->entityId, $documents[0]->entityId);
        $context = ResolveCurrentEstimateClarification::composerAnswerContext($answer, $snapshot, $decisions, $session->input_payload);
        self::assertNotNull($context);
        self::assertNotSame($context['entity_id'], $documents[0]->entityId);
        self::assertNotSame($documents[0]->entityId, $documents[1]->entityId);
        self::assertSame($documents[0]->value, $documents[1]->value);
        foreach ([
            [...$candidate, 'document_fact_ids' => [$documents[1]->id]],
            [...$candidate, 'entity_id' => $documents[1]->entityId],
            [...$candidate, 'value' => ['type' => 'string', 'data' => 'Монтаж стен']],
            [...$candidate, 'value' => ['type' => 'string', 'data' => 'Установка стендов']],
            [...$candidate, 'document_fact_ids' => [$answer->id]],
        ] as $unsupported) {
            self::assertNull($predicate->capture($scope, $snapshot, $decisions, $session->input_payload, $unsupported, $anchors));
        }
        self::assertNull($predicate->capture([$scope[0] + 10000, $scope[1], $scope[2]], $snapshot, $decisions, $session->input_payload, $candidate, $anchors));
        self::assertNull($predicate->capture($scope, $snapshot, $decisions, $session->input_payload, $candidate, []));
        self::assertSame('Требуется установить оборудование согласно проекту.', $models->fact(...[...$scope, $answer->id])->value['other']);
        Http::assertNothingSent();
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['estimate-composer:answers:v2'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['estimate-composer:answers:v3'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['estimate-composer:answers:v4'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['estimate-composer:answer-cells:v1'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['estimate-composer:answer-cells:v2'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['estimate-composer:answer-cells:v3'])]
    public function test_terminal_source_info_fixture_records_one_normless_claim_and_reader_rechecks_the_current_scoped_source(string $contract): void
    {
        [$actor, $session, $answer, $documents] = $this->answeredSourceFixture('  Монтаж неизвестной установки  ');
        $candidate = ['source_answer_fact_id' => $answer->id, 'entity_id' => $documents[0]->entityId,
            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => 'Монтаж стендов'],
            'unit' => null, 'document_fact_ids' => [$documents[0]->id]];
        $organization = Organization::query()->findOrFail($session->organization_id);
        $project = Project::query()->findOrFail($session->project_id);
        [$session, $operationId, $runId] = $this->paidReceipt($contract, [$candidate], true,
            [$organization, $project, $actor, $session], false);
        [$claim, $result] = $this->terminalFixture($actor, $session, $operationId);
        Queue::fake();
        DB::transaction(fn () => app(Pipeline\PublishValidatedDraft::class)->beforeComplete($claim, $result, new \DateTimeImmutable));
        $rows = EstimateGenerationLearningExample::query()->where('organization_id', $organization->id)->get();
        self::assertCount(1, $rows);
        self::assertSame($runId, $rows[0]->source_entity_id);
        self::assertNull($rows[0]->norm_code);
        self::assertFalse($rows[0]->is_positive);
        self::assertNull($rows[0]->accepted_at);
        self::assertFalse($rows[0]->context_payload['training_allowed']);
        $session = $session->fresh();
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
        $snapshot = $capture['snapshot'];
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        $reader = app(EstimateGenerationLearningEvidenceService::class);
        $refs = $reader->sourceClaimReferences(...[...$scope, $snapshot, $decisions, $session->input_payload]);
        self::assertCount(1, $refs);
        self::assertSame(10, count($refs[0]));
        self::assertSame($answer->id, $refs[0]['source_answer_fact_id']);
        self::assertSame($documents[0]->entityId, $refs[0]['entity_id']);
        self::assertSame('passed', $refs[0]['source_correspondence']);
        self::assertSame('proposed', $refs[0]['interpretation_status']);
        self::assertSame('unresolved', $refs[0]['engineering_applicability']);
        self::assertSame($refs, $reader->sourceClaimReferences(...[...$scope, $snapshot, $decisions,
            [...$session->input_payload, 'description' => trim($session->input_payload['description'])]]));
        self::assertSame(0, DB::transaction(fn () => app(EstimateGenerationLearningRecorder::class)->recordComposerSourceClaims(...[...$scope, $operationId])));
        self::assertSame($refs, $reader->sourceClaimReferences(...[...$scope, $snapshot, $decisions, $session->input_payload]));
        self::assertSame([], $reader->sourceClaimReferences($scope[0] + 10000, $scope[1], $scope[2], $snapshot, $decisions, $session->input_payload));
        self::assertSame([], $reader->sourceClaimReferences($scope[0], $scope[1] + 10000, $scope[2], $snapshot, $decisions, $session->input_payload));
        self::assertSame([], $reader->sourceClaimReferences($scope[0], $scope[1], $scope[2] + 10000, $snapshot, $decisions, $session->input_payload));
        self::assertSame([], $reader->sourceClaimReferences(...[...$scope, $snapshot, $decisions, [...$session->input_payload, 'description' => 'Подменённое описание']]));
        self::assertSame($capture['token'], $models->snapshotForPlanning(...[...$scope, 10001])['token']);
        self::assertSame(1, DB::table('estimate_generation_ai_usage')->where('organization_id', $organization->id)->count());
        Queue::assertNothingPushed();
        $document = EstimateGenerationDocument::query()->where('session_id', $session->id)->sole();
        self::assertContains((string) $document->status, ['ready', 'needs_review']);
        app(Documents\IgnoreEstimateGenerationDocument::class)->handle($session, $document, (int) $session->state_version,
            'Источник исключён после проверки ссылки.');
        self::assertSame('ignored', $document->fresh()->status);
        self::assertSame([], $reader->sourceClaimReferences(...[...$scope, $snapshot, $decisions, $session->input_payload]));
        $current = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $currentDecisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($current->facts, 'id')]);
        self::assertSame([], $reader->sourceClaimReferences(...[...$scope, $current, $currentDecisions, $session->fresh()->input_payload]));
        self::assertSame(1, EstimateGenerationLearningExample::query()->where('organization_id', $organization->id)->count());
        Http::assertNothingSent();
    }

    public function test_reader_distinguishes_two_current_conflicts_from_a_rejected_historical_source_after_public_ignore(): void
    {
        [$actor, $session, $answer, $documents] = $this->answeredSourceFixture();
        $organization = Organization::query()->findOrFail($session->organization_id);
        $project = Project::query()->findOrFail($session->project_id);
        $firstDocument = EstimateGenerationDocument::query()->where('session_id', $session->id)->sole();
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $firstCandidate = ['source_answer_fact_id' => $answer->id, 'entity_id' => $documents[0]->entityId,
            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => 'Монтаж стендов'],
            'unit' => null, 'document_fact_ids' => [$documents[0]->id]];
        $this->publishBinarySource($session);
        $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $newSources = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'equipment_specification'
            && ! in_array($fact->id, array_column($documents, 'id'), true)));
        self::assertCount(2, $newSources);
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        self::assertNotNull(ResolveCurrentEstimateClarification::composerAnswerContext($answer, $snapshot, $decisions, $session->input_payload));
        [$session, $operationId] = $this->paidReceipt('estimate-composer:answers:v2', [$firstCandidate], true,
            [$organization, $project, $actor, $session->fresh()], false);
        [$claim, $result] = $this->terminalFixture($actor, $session, $operationId);
        DB::transaction(fn () => app(Pipeline\PublishValidatedDraft::class)->beforeComplete($claim, $result, new \DateTimeImmutable));
        $session = $session->fresh();
        $secondCandidate = [...$firstCandidate, 'entity_id' => $newSources[0]->entityId, 'document_fact_ids' => [$newSources[0]->id]];
        [$session, $operationId] = $this->paidReceipt('estimate-composer:answers:v2', [$secondCandidate], true,
            [$organization, $project, $actor, $session], false);
        [$claim, $result] = $this->terminalFixture($actor, $session, $operationId);
        DB::transaction(fn () => app(Pipeline\PublishValidatedDraft::class)->beforeComplete($claim, $result, new \DateTimeImmutable));
        $session = $session->fresh();
        $reader = app(EstimateGenerationLearningEvidenceService::class);
        self::assertSame([], $reader->sourceClaimReferences(...[...$scope, $snapshot, $decisions, $session->input_payload]));
        self::assertSame(2, EstimateGenerationLearningExample::query()->where('organization_id', $organization->id)->count());
        app(Documents\IgnoreEstimateGenerationDocument::class)->handle($session, $firstDocument, (int) $session->state_version,
            'Первый документ больше не используется.');
        $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        self::assertNotContains($documents[0]->id, array_column($snapshot->facts, 'id'));
        self::assertNotNull(ResolveCurrentEstimateClarification::composerAnswerContext($answer, $snapshot, $decisions, $session->fresh()->input_payload));
        $refs = $reader->sourceClaimReferences(...[...$scope, $snapshot, $decisions, $session->fresh()->input_payload]);
        self::assertCount(1, $refs);
        self::assertSame($newSources[0]->entityId, $refs[0]['entity_id']);
        self::assertSame([$newSources[0]->id], $refs[0]['document_fact_ids']);
        self::assertSame(2, EstimateGenerationLearningExample::query()->where('organization_id', $organization->id)->count());
        Http::assertNothingSent();
    }

    public function test_optional_learning_source_filter_budget_does_not_undo_terminal_publication_or_retry_paid_usage(): void
    {
        [$actor, $session, $answer, $documents] = $this->answeredSourceFixture();
        $organization = Organization::query()->findOrFail($session->organization_id);
        $project = Project::query()->findOrFail($session->project_id);
        $ignoredDocument = EstimateGenerationDocument::query()->where('session_id', $session->id)->sole();
        $this->publishBinarySource($session);
        app(Documents\IgnoreEstimateGenerationDocument::class)->handle($session->fresh(), $ignoredDocument,
            (int) $session->fresh()->state_version, 'Предыдущий источник исключён из текущего проекта.');
        $session = $session->fresh();
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $current = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $currentDocuments = array_values(array_filter($current->facts, static fn ($fact): bool => $fact->type === 'equipment_specification'));
        self::assertCount(2, $currentDocuments);
        self::assertNotContains($documents[0]->id, array_column($current->facts, 'id'));
        $candidate = ['source_answer_fact_id' => $answer->id, 'entity_id' => $currentDocuments[0]->entityId,
            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => 'Монтаж стендов'],
            'unit' => null, 'document_fact_ids' => [$currentDocuments[0]->id]];
        [$session, $operationId, $runId] = $this->paidReceipt('estimate-composer:answers:v2', [$candidate], true,
            [$organization, $project, $actor, $session], false);
        [$claim, $result] = $this->terminalFixture($actor, $session, $operationId, true);
        $this->fillUnresolvedPlanningBudget($session, 10001);
        $raw = $models->snapshotForUnderstanding(...[...$scope, 10001])['snapshot'];
        self::assertCount(10001, $raw->facts);
        self::assertContains($documents[0]->id, array_column($raw->facts, 'id'));
        try {
            $models->snapshotForPlanning(...[...$scope, 10001]);
            self::fail('The actual limited planning snapshot did not report its source-filter budget.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('planning_source_filter_budget_exceeded', $exception->getMessage());
        }
        $usage = DB::table('estimate_generation_ai_usage')->where('organization_id', $organization->id)->get()->all();
        $role = DB::table('estimate_generation_ai_role_runs')->where('id', $runId)->sole();
        $store = app(Pipeline\PipelineCheckpointStore::class);
        self::assertTrue($store->complete($claim, $result, new \DateTimeImmutable));
        self::assertSame('input_review_required', $session->fresh()->status->value);
        self::assertSame('settled', DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->value('status'));
        self::assertSame('completed', DB::table('estimate_generation_pipeline_checkpoints')->where('id', $claim->checkpointId)->value('status'));
        self::assertSame(0, EstimateGenerationLearningExample::query()->where('organization_id', $organization->id)->count());
        $settled = $this->localCreditState((int) $organization->id);
        self::assertFalse($store->complete($claim, $result, new \DateTimeImmutable));
        self::assertSame($settled, $this->localCreditState((int) $organization->id));
        self::assertEquals($usage, DB::table('estimate_generation_ai_usage')->where('organization_id', $organization->id)->get()->all());
        self::assertEquals($role, DB::table('estimate_generation_ai_role_runs')->where('id', $runId)->sole());
        Http::assertNothingSent();
    }

    public function test_terminal_checkpoint_rolls_back_local_settlement_on_learning_database_error_and_retries_without_another_paid_attempt(): void
    {
        [$actor, $session, $answer, $documents] = $this->answeredSourceFixture();
        $candidate = ['source_answer_fact_id' => $answer->id, 'entity_id' => $documents[0]->entityId,
            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => 'Монтаж стендов'],
            'unit' => null, 'document_fact_ids' => [$documents[0]->id]];
        $organization = Organization::query()->findOrFail($session->organization_id);
        $project = Project::query()->findOrFail($session->project_id);
        [$session, $operationId, $runId] = $this->paidReceipt('estimate-composer:answers:v2', [$candidate], true,
            [$organization, $project, $actor, $session], false);
        [$fixtureClaim, $result] = $this->terminalFixture($actor, $session, $operationId);
        $context = $fixtureClaim->context;
        $now = new \DateTimeImmutable;
        DB::table('estimate_generation_pipeline_checkpoints')->insert([
            'organization_id' => $context->organizationId, 'project_id' => $context->projectId, 'session_id' => $context->sessionId,
            'generation_attempt_id' => $operationId, 'base_input_version' => $context->baseInputVersion,
            'stage' => Pipeline\ProcessingStage::BuildDraft->value, 'input_version' => $context->baseInputVersion,
            'dependency_versions' => '{}', 'output_version' => $context->dependencyVersions[Pipeline\ProcessingStage::BuildDraft->value],
            'output_payload' => '{}', 'artifact_bytes' => 2, 'status' => 'completed', 'metrics' => '{}', 'warnings' => '[]',
            'attempt_count' => 1, 'started_at' => $now, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $store = app(Pipeline\PipelineCheckpointStore::class);
        $claim = $store->claim($context, Pipeline\ProcessingStage::ValidateDraft, $now, $now->modify('+5 minutes'));
        self::assertSame(Pipeline\CheckpointClaimStatus::Acquired, $claim->status);
        $before = $this->localCreditState((int) $organization->id);
        $sessionBefore = $session->fresh()->getAttributes();
        $roleBefore = DB::table('estimate_generation_ai_role_runs')->where('id', $runId)->sole();
        $usageBefore = DB::table('estimate_generation_ai_usage')->where('organization_id', $organization->id)->get()->all();
        $indexing = app(AssistantIndexingState::class);
        $indexing->endMigration();
        self::assertFalse($indexing->paused());
        \Tests\Support\AssistantRagTestSchema::create();
        $ragRunsBefore = RagIndexRun::query()->count();
        Queue::fake();
        DB::unprepared("CREATE FUNCTION pg_temp.reject_source_claim_fixture() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF NEW.source_type = 'composer_source_claim' AND NEW.organization_id = ".(int) $organization->id." THEN
                    IF NOT EXISTS (SELECT 1 FROM estimate_generation_credit_operations WHERE operation_id = '".$operationId."' AND status = 'settled') THEN
                        RAISE EXCEPTION 'source_claim_fixture_failed_before_settlement';
                    END IF;
                    RAISE EXCEPTION 'source_claim_fixture_insert_failure_after_settlement' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END \$\$;
            CREATE TRIGGER reject_source_claim_fixture BEFORE INSERT ON estimate_generation_learning_examples
            FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_source_claim_fixture()");
        try {
            try {
                $store->complete($claim, $result, $now->modify('+1 second'));
                self::fail('A rejected Learning INSERT did not abort terminal completion.');
            } catch (QueryException $exception) {
                self::assertSame('23514', $exception->errorInfo[0]);
                self::assertStringContainsString('source_claim_fixture_insert_failure_after_settlement', $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER reject_source_claim_fixture ON estimate_generation_learning_examples; DROP FUNCTION pg_temp.reject_source_claim_fixture()');
        }
        self::assertSame($before, $this->localCreditState((int) $organization->id));
        self::assertSame($sessionBefore, $session->fresh()->getAttributes());
        self::assertEquals($roleBefore, DB::table('estimate_generation_ai_role_runs')->where('id', $runId)->sole());
        self::assertEquals($usageBefore, DB::table('estimate_generation_ai_usage')->where('organization_id', $organization->id)->get()->all());
        self::assertSame('running', DB::table('estimate_generation_pipeline_checkpoints')->where('id', $claim->checkpointId)->value('status'));
        self::assertSame(0, EstimateGenerationLearningExample::query()->where('organization_id', $organization->id)->count());
        self::assertTrue($store->complete($claim, $result, $now->modify('+2 seconds')));
        self::assertSame('settled', DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->value('status'));
        self::assertSame('completed', DB::table('estimate_generation_pipeline_checkpoints')->where('id', $claim->checkpointId)->value('status'));
        self::assertSame(1, EstimateGenerationLearningExample::query()->where('organization_id', $organization->id)->count());
        $settled = $this->localCreditState((int) $organization->id);
        self::assertFalse($store->complete($claim, $result, $now->modify('+3 seconds')));
        self::assertSame($settled, $this->localCreditState((int) $organization->id));
        self::assertSame(1, DB::table('estimate_generation_ai_usage')->where('organization_id', $organization->id)->count());
        self::assertEquals($roleBefore, DB::table('estimate_generation_ai_role_runs')->where('id', $runId)->sole());
        self::assertSame($ragRunsBefore, RagIndexRun::query()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $indexing->beginMigration();
    }

    private function localCreditState(int $organizationId): array
    {
        $result = [];
        foreach (['estimate_generation_credit_operations', 'estimate_generation_credit_attempts', 'ai_credit_provider_usages',
            'ai_credit_reservations', 'ai_credit_wallets', 'ai_credit_lots', 'ai_credit_ledger_entries',
            'ai_credit_provider_cost_allocations', 'ai_credit_reservation_allocations'] as $table) {
            $query = DB::table($table);
            if ($table === 'ai_credit_reservation_allocations') {
                $query->whereIn('ai_credit_reservation_id', DB::table('ai_credit_reservations')->select('id')->where('organization_id', $organizationId));
            } elseif ($table === 'ai_credit_provider_cost_allocations') {
                $query->whereIn('source_provider_usage_id', DB::table('ai_credit_provider_usages')->select('id')->where('organization_id', $organizationId));
            } else {
                $query->where('organization_id', $organizationId);
            }
            $rows = $query->get()
                ->map(static fn ($row): string => CanonicalPipelineJson::encode(get_object_vars($row)))->all();
            sort($rows, SORT_STRING);
            $result[$table] = $rows;
        }

        return $result;
    }

    public function test_norm_optional_shape_preserves_old_norm_requirement_and_denies_existing_trust_channels(): void
    {
        $row = $this->row();
        $id = DB::table('estimate_generation_learning_examples')->insertGetId($row);
        $example = EstimateGenerationLearningExample::query()->findOrFail($id);
        self::assertNull($example->norm_code);
        self::assertNull($example->estimate_norm_id);
        self::assertNull($example->normative_dataset_version_id);
        self::assertSame(str_repeat('я', 500), $example->work_name);
        self::assertFalse($example->is_positive);
        self::assertNull($example->accepted_at);
        self::assertFalse(EstimateGenerationLearningSourceTrustPolicy::isIndexable($example));
        foreach ([
            ['source_type' => 'user_selection', 'source_claim_key' => null],
            ['source_type' => 'composer_source_claim', 'norm_code' => 'fake'],
            ['source_claim_key' => null], ['source_claim_key' => str_repeat('z', 64)],
            ['source_entity_type' => null], ['is_positive' => true], ['decision_status' => 'confirmed_by_user'],
            ['accepted_at' => now()],
        ] as $change) {
            try {
                DB::transaction(fn () => DB::table('estimate_generation_learning_examples')->insert([...$row, ...$change]));
                self::fail('A norm-optional row escaped the closed source-claim shape.');
            } catch (QueryException $exception) {
                self::assertSame('23514', $exception->errorInfo[0]);
            }
        }
        self::assertSame(1, DB::table('estimate_generation_learning_examples')->where('organization_id', $row['organization_id'])->count());
    }

    public function test_logical_key_is_unique_in_exact_scope_even_when_another_physical_run_is_proposed(): void
    {
        $row = $this->row();
        DB::table('estimate_generation_learning_examples')->insert($row);
        try {
            DB::transaction(fn () => DB::table('estimate_generation_learning_examples')->insert([...$row, 'source_entity_id' => 2]));
            self::fail('A repeated logical claim was inserted for another physical run.');
        } catch (QueryException $exception) {
            self::assertSame('23505', $exception->errorInfo[0]);
        }
        self::assertSame(1, DB::table('estimate_generation_learning_examples')->where('organization_id', $row['organization_id'])->count());
    }

    #[DataProvider('receiptCases')]
    public function test_loader_reads_only_closed_completed_answered_receipts_of_the_exact_settled_operation(string $contract, mixed $candidates, bool $accepted, bool $measured = true): void
    {
        Http::preventStrayRequests();
        Http::fake();
        [$session, $operationId, $runId] = $this->paidReceipt($contract, $candidates, $measured);
        $recorder = app(EstimateGenerationLearningRecorder::class);
        $expected = $accepted ? [['run_id' => $runId, 'candidate' => $this->candidate(),
            ...(in_array($contract, \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput::WORK_SOURCE_CLAIM_CONTRACTS, true)
                ? ['contract_version' => $contract] : [])]] : [];
        self::assertEquals($expected, $recorder->sourceClaimCandidatesForOperation($session, $operationId));
        self::assertEquals($expected, $recorder->sourceClaimCandidatesForOperation($session, $operationId));
        self::assertSame([], $recorder->sourceClaimCandidatesForOperation($session, (string) Str::uuid()));
        $foreign = $this->fixture()[3];
        self::assertSame([], $recorder->sourceClaimCandidatesForOperation($foreign, $operationId));
        self::assertSame(0, DB::table('estimate_generation_learning_examples')->where('organization_id', $session->organization_id)->count());
        self::assertSame(1, DB::table('estimate_generation_ai_usage')->where('organization_id', $session->organization_id)->count());
        Http::assertNothingSent();
    }

    public static function receiptCases(): array
    {
        $candidate = ['source_answer_fact_id' => 'fact:answer', 'entity_id' => 'entity:equipment',
            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => 'Насос по спецификации.'],
            'unit' => null, 'document_fact_ids' => ['fact:document']];

        return [
            'answered-v2' => ['estimate-composer:answers:v2', [$candidate], true],
            'settled-unavailable-cost' => ['estimate-composer:answers:v2', [$candidate], true, false],
            'legacy-answered-v2' => ['estimate-composer:legacy-answers:v2', [$candidate], true],
            'answered-v3' => ['estimate-composer:answers:v3', [$candidate], true],
            'legacy-answered-v3' => ['estimate-composer:legacy-answers:v3', [$candidate], true],
            'answered-v4' => ['estimate-composer:answers:v4', [$candidate], true],
            'legacy-answered-v4' => ['estimate-composer:legacy-answers:v4', [$candidate], true],
            'answered-v5' => ['estimate-composer:answers:v5', [$candidate], true],
            'legacy-answered-v5' => ['estimate-composer:legacy-answers:v5', [$candidate], true],
            'answer-cells-v1' => ['estimate-composer:answer-cells:v1', [$candidate], true],
            'legacy-answer-cells-v1' => ['estimate-composer:legacy-answer-cells:v1', [$candidate], true],
            'answer-cells-v2' => ['estimate-composer:answer-cells:v2', [$candidate], true],
            'legacy-answer-cells-v2' => ['estimate-composer:legacy-answer-cells:v2', [$candidate], true],
            'answer-cells-v3' => ['estimate-composer:answer-cells:v3', [$candidate], true],
            'legacy-answer-cells-v3' => ['estimate-composer:legacy-answer-cells:v3', [$candidate], true],
            'answer-cells-v4' => ['estimate-composer:answer-cells:v4', [$candidate], true],
            'legacy-answer-cells-v4' => ['estimate-composer:legacy-answer-cells:v4', [$candidate], true],
            'unknown future' => ['estimate-composer:answers:v999', [$candidate], false],
            'unknown future cells' => ['estimate-composer:answer-cells:v999', [$candidate], false],
            'no-answer' => ['estimate-composer:v4', [$candidate], false],
            'model-verdict' => ['estimate-composer:answers:v2', [[...$candidate, 'passed' => true]], false],
            'non-array' => ['estimate-composer:answers:v2', 'not-an-array', false],
            'oversized' => ['estimate-composer:answers:v2', array_fill(0, 9, $candidate), false],
        ];
    }

    public function test_old_and_new_completed_identities_exceeding_one_frozen_part_stay_unresolved(): void
    {
        $fixture = $this->fixture();
        [$session, $operationId] = $this->paidReceipt('estimate-composer:answers:v2', [$this->candidate()], fixture: $fixture, settle: false, maxWorkItems: 1);
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->sole();
        $plan = json_decode($operation->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(1, $plan['composer_part_limit']);
        self::assertSame(3, $plan['roles']['estimate_composition']['max_calls']);
        $this->paidReceipt('estimate-composer:answers:v3', [$this->candidate()], fixture: $fixture, reuseOperationId: $operationId);
        self::assertSame('settled', DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->value('status'));
        self::assertSame(2, DB::table('estimate_generation_credit_attempts')->where('operation_id', $operationId)
            ->where('role', 'estimate_composition')->where('status', 'recorded')->count());
        self::assertSame(2, DB::table('estimate_generation_ai_role_runs')->where('organization_id', $session->organization_id)
            ->where('project_id', $session->project_id)->where('session_id', $session->id)->where('status', 'completed')->count());
        self::assertSame([], app(EstimateGenerationLearningRecorder::class)->sourceClaimCandidatesForOperation($session->fresh(), $operationId));
        self::assertSame(0, DB::table('estimate_generation_learning_examples')->where('organization_id', $session->organization_id)->count());
        Http::assertNothingSent();
    }

    private function sourceHeaders(User $actor): array
    {
        $uuid = (string) Str::uuid();
        $session = UserAuthSession::query()->create(['user_id' => $actor->id, 'organization_id' => $actor->current_organization_id,
            'session_uuid' => $uuid, 'device_fingerprint' => hash('sha256', $uuid), 'device_name' => 'MOST source proof readback',
            'ip_address' => '127.0.0.1', 'risk_score' => 0, 'risk_flags' => [], 'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(), 'last_seen_at' => now()]);
        $tokens = app(WebAuthTokenService::class)->issue($actor, 'admin', $session->session_uuid, (int) $actor->current_organization_id, false);

        return ['Accept' => 'application/json', 'Origin' => 'https://admin.fixture.test', 'Authorization' => 'Bearer '.$tokens->accessToken];
    }

    private function paidReceipt(string $contract, mixed $candidates, bool $measured = true, ?array $fixture = null, bool $settle = true, ?callable $compose = null, ?string $reuseOperationId = null, ?int $maxWorkItems = null): array
    {
        [$organization, $project, $actor, $session] = $fixture ?? $this->fixture();
        $entitlements = \Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $entitlements->allows('hasModuleAccess')->andReturn(true);
        $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class, $entitlements);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $permissions->allows('can')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        config(['ai-assistant-credits.enforce' => false, 'estimate-generation.credits.enabled' => true, 'estimate-generation.live_qa.enabled' => false]);
        if (! $actor->organizations()->whereKey($organization->id)->exists()) {
            $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        }
        $operationId = $reuseOperationId ?? (string) Str::uuid();
        if ($reuseOperationId === null) {
            app(AICreditService::class)->grant($organization, 1000000, 'purchase', null, 'test:source-claim:'.$organization->id);
            $operations = app(EstimatorCreditOperations::class);
            $quote = $operations->quote($actor, (int) $project->id, (int) $session->id, (int) $session->state_version, $operationId, 'generation',
                $maxWorkItems === null ? [] : ['max_work_items' => $maxWorkItems]);
            DB::transaction(fn () => $operations->begin($actor, $session, (int) $session->state_version, $operationId, $quote['quote_id'], 'generation'));
        }
        if ($compose !== null) {
            $runId = $compose($session, $operationId);
            if ($settle) {
                $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->sole();
                DB::transaction(fn () => app(EstimatorCreditOperationSettlement::class)->publish($session, $operationId,
                    ['source_input_version' => $operation->source_version, 'local_estimates' => []]));
            }

            return [$session, $operationId, $runId];
        }
        $input = new AiRoleRunInput((int) $organization->id, (int) $project->id, (int) $session->id, null, null,
            'estimate_session', (string) $session->id, hash('sha256', $operationId), AiAnalysisRole::EstimateComposer,
            'openai/gpt-6-luna', $contract, hash('sha256', 'source-claim-input:'.$operationId.':'.$contract));
        $runs = app(AiRoleRunRepository::class);
        $owner = (string) Str::uuid();
        $claim = $runs->claim($input, $owner);
        $attempt = (string) Str::uuid();
        $runs->startPhysicalAttempt($claim->runId, $owner, $attempt, $operationId, 'estimate_composition');
        DB::transaction(fn () => app(EstimatorCreditAttemptAdmission::class)->admit($operationId, $attempt,
            (int) $organization->id, (int) $project->id, (int) $session->id, 'estimate_composition', new AiCost('1.00000000', 'RUB', 'available')));
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->sole();
        $identity = json_decode($operation->credit_request, true, 64, JSON_THROW_ON_ERROR);
        app(EloquentAiUsageStore::class)->record(new AiUsageData(new AiOperationContext((string) Str::uuid(), $attempt,
            (int) $organization->id, (int) $project->id, (int) $session->id, 'plan_work_items', 'estimate_composition', 1, creditOperationId: $operationId),
            'timeweb', 'openai/gpt-6-luna', 'succeeded', 10, reportedModel: 'openai/gpt-6-luna',
            usageStatus: $measured ? 'measured' : 'unavailable', inputTokens: $measured ? 1000 : 0, outputTokens: $measured ? 100 : 0,
            priceSnapshot: AiPriceSnapshot::fromArray($identity['supplier_tariff'])));
        $cellContract = str_contains($contract, 'cells:');
        $runs->complete($claim->runId, $owner, new AiRoleRunResult(['schema_version' => $cellContract ? 3 : 2, 'role' => 'estimate_composer',
            'work_intents' => [], 'quarantined_intents' => [], 'result_state' => 'ready', 'claim_candidates' => $candidates,
            ...($cellContract ? ['cell_mapping_proposals' => [], 'cell_mapping_context' => []] : [])], $attempt));
        if ($settle) {
            DB::transaction(fn () => app(EstimatorCreditOperationSettlement::class)->publish($session, $operationId,
                ['source_input_version' => $operation->source_version, 'local_estimates' => []]));
        }

        return [$session, $operationId, $claim->runId];
    }

    private function terminalFixture(User $actor, EstimateGenerationSession $session, string $operationId, bool $acquireCheckpoint = false): array
    {
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->sole();
        $session->forceFill(['status' => 'generating', 'input_payload' => [...$session->input_payload,
            'generation_actor_type' => 'user', 'generation_actor_id' => $actor->id,
            'generation_attempt_id' => $operationId, 'generation_credit_operation_id' => $operationId,
            'generation_evaluation_input_hash' => $operation->input_hash]])->save();
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $capture = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001]);
        $draft = (new BuildMostEstimateDraft)->seal((new UniversalReviewSummaryProjector)->project([
            'generation_contract' => UniversalDraftProjector::CONTRACT, 'source_info_only' => true, 'is_complete' => false,
            'evaluation_result' => null, 'local_estimates' => [], 'stage6_candidate_rows' => [], 'source_input_version' => $operation->source_version,
            'scope_identity' => ['organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2]],
            'evaluation_policy' => ['mode' => 'universal', 'profile_id' => 'construction', 'selected_sections' => ['equipment']],
            'estimate_composition' => ['schema_version' => 1, 'snapshot_token' => $capture['token'], 'input_fingerprint' => str_repeat('b', 64),
                'request_info' => [['index' => 0, 'reason' => 'estimate_composer_work_scope_unresolved', 'work_name' => null,
                    'next_action' => 'provide_work_scope', 'source_fact_ids' => []]]],
        ]));
        $context = new Pipeline\PipelineContext((int) $session->id, (int) $session->organization_id, (int) $session->project_id,
            (int) $session->state_version, $operation->source_version, 'generating', generationAttemptId: $operationId,
            baseInputVersion: $operation->source_version, stage: Pipeline\ProcessingStage::ValidateDraft,
            dependencyVersions: [Pipeline\ProcessingStage::BuildDraft->value => $operation->source_version], creditOperationId: $operationId);
        $result = (new Pipeline\Stages\StageResultFactory(new Pipeline\InMemoryPipelineArtifactStore, Pipeline\PipelineDefinitionGraph::standard()))
            ->make($context, Pipeline\ProcessingStage::ValidateDraft, ['draft' => $draft, 'requires_review' => true]);
        if ($acquireCheckpoint) {
            $now = new \DateTimeImmutable;
            DB::table('estimate_generation_pipeline_checkpoints')->insert([
                'organization_id' => $context->organizationId, 'project_id' => $context->projectId, 'session_id' => $context->sessionId,
                'generation_attempt_id' => $operationId, 'base_input_version' => $context->baseInputVersion,
                'stage' => Pipeline\ProcessingStage::BuildDraft->value, 'input_version' => $context->baseInputVersion,
                'dependency_versions' => '{}', 'output_version' => $context->dependencyVersions[Pipeline\ProcessingStage::BuildDraft->value],
                'output_payload' => '{}', 'artifact_bytes' => 2, 'status' => 'completed', 'metrics' => '{}', 'warnings' => '[]',
                'attempt_count' => 1, 'started_at' => $now, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);

            return [app(Pipeline\PipelineCheckpointStore::class)->claim($context, Pipeline\ProcessingStage::ValidateDraft,
                $now, $now->modify('+5 minutes')), $result];
        }

        return [Pipeline\CheckpointClaim::acquired($context, Pipeline\ProcessingStage::ValidateDraft, (string) Str::uuid()), $result];
    }

    private function fillUnresolvedPlanningBudget(EstimateGenerationSession $session, int $total): void
    {
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $source = 'sha256:'.hash('sha256', 'source-claim-budget-fixture:'.$session->id);
        $entity = new Entity('entity:zz-budget:template', ...[...$scope, $source, 'quantity', 'entity:zz-budget:template',
            ['semantic_type' => 'quantity', 'identity' => ['input_field' => 'description']]]);
        $fact = new Fact('fact:zz-budget:template', ...[...$scope, $source, $entity->id, 'evaluation_answer',
            ['kind' => 'budget_context_fixture'], null, 0.0, 'unresolved', 'unresolved', []]);
        app(ProjectModelRepository::class)->saveSourceModel([$entity], [$fact], []);
        $query = static fn (string $table) => DB::table($table)->where('organization_id', $scope[0])
            ->where('project_id', $scope[1])->where('session_id', $scope[2]);
        $entityTemplate = (array) $query('estimate_generation_project_model_entities')->where('stable_key', $entity->id)->sole();
        $factTemplate = (array) $query('estimate_generation_project_model_assertions')->where('stable_key', $fact->id)->sole();
        $projectionTemplate = (array) $query('estimate_generation_project_model_fact_projections')->where('fact_id', $factTemplate['id'])->sole();
        unset($entityTemplate['id'], $factTemplate['id'], $projectionTemplate['id']);
        $remaining = $total - $query('estimate_generation_project_model_fact_projections')->where('is_current', true)->count();
        self::assertGreaterThan(0, $remaining);
        $entityRows = [];
        for ($index = 0; $index < $remaining; $index++) {
            $key = 'zz-budget:'.str_pad((string) $index, 6, '0', STR_PAD_LEFT);
            $entityRows[] = [...$entityTemplate, 'stable_key' => 'entity:'.$key,
                'payload' => json_encode(['kind' => 'quantity', 'key' => 'entity:'.$key,
                    'semantic_type' => 'quantity', 'identity' => ['input_field' => 'description']], JSON_THROW_ON_ERROR)];
        }
        foreach (array_chunk($entityRows, 500) as $chunk) {
            DB::table('estimate_generation_project_model_entities')->insert($chunk);
        }
        $entities = $query('estimate_generation_project_model_entities')->where('stable_key', 'like', 'entity:zz-budget:%')->pluck('id', 'stable_key');
        $factRows = [];
        foreach ($entityRows as $row) {
            $factRows[] = [...$factTemplate, 'stable_key' => str_replace('entity:', 'fact:', $row['stable_key']), 'entity_id' => $entities[$row['stable_key']]];
        }
        foreach (array_chunk($factRows, 500) as $chunk) {
            DB::table('estimate_generation_project_model_assertions')->insert($chunk);
        }
        $facts = $query('estimate_generation_project_model_assertions')->where('stable_key', 'like', 'fact:zz-budget:%')->pluck('id', 'stable_key');
        $projections = [];
        foreach ($entityRows as $row) {
            $projections[] = [...$projectionTemplate, 'entity_stable_key' => $row['stable_key'],
                'fact_id' => $facts[str_replace('entity:', 'fact:', $row['stable_key'])]];
        }
        foreach (array_chunk($projections, 500) as $chunk) {
            DB::table('estimate_generation_project_model_fact_projections')->insert($chunk);
        }
    }

    private function candidate(): array
    {
        return self::receiptCases()['answered-v2'][1][0];
    }

    private function row(): array
    {
        [$organization, $project, , $session] = $this->fixture();

        return ['organization_id' => $organization->id, 'project_id' => $project->id, 'generation_session_id' => $session->id,
            'source_type' => 'composer_source_claim', 'source_entity_type' => 'ai_role_run', 'source_entity_id' => 1,
            'source_claim_key' => str_repeat('a', 64), 'work_name' => str_repeat('я', 500), 'norm_code' => null,
            'decision_status' => 'proposed', 'is_positive' => false, 'accepted_at' => null,
            'created_at' => now(), 'updated_at' => now()];
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->createQuietly();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'state_version' => 0, 'status' => 'draft', 'input_payload' => []]);

        return [$organization, $project, $actor, $session];
    }

    private function answeredSourceFixture(string $description = 'Монтаж неизвестной установки', bool $naturalInitial = false, ?string $uploadMoment = null, string $targetMode = 'unique', array $selectedSections = ['equipment'], ?User $realActor = null, bool $lengthOperand = false): array
    {
        Http::fake();
        Http::preventStrayRequests();
        $organization = $realActor === null ? Organization::factory()->create() : Organization::query()->findOrFail($realActor->current_organization_id);
        $project = Project::factory()->for($organization)->createQuietly();
        $actor = $realActor ?? User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        if ($realActor === null) {
            $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        }
        $authorization = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $authorization->allows('can')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $authorization);
        config(['estimate-generation.universal_enabled' => true]);
        $input = ['evaluation_mode' => 'universal', 'profile_id' => 'construction', 'building_type' => 'bridge',
            'selected_sections' => $selectedSections, 'generation_mode' => 'ai_assisted', 'description' => $description];
        $session = app(CreateEstimateGenerationSession::class)->handle(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'input_payload' => $input], $actor);
        $this->publishBinarySource($session, $targetMode === 'mixed', lengthOperand: $lengthOperand);
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
        $documents = array_values(array_filter($capture['snapshot']->facts, static fn ($fact): bool => $fact->type === 'equipment_specification'
            || ($targetMode === 'mixed' && $fact->type === 'equipment_count')));
        self::assertCount(2, $documents);
        if ($targetMode === 'mixed') {
            usort($documents, static fn (Fact $left, Fact $right): int => ($right->type === 'equipment_specification') <=> ($left->type === 'equipment_specification'));
            self::assertSame('equipment_count', $documents[1]->type);
            self::assertNotSame($documents[0]->entityId, $documents[1]->entityId);
        }
        if ($naturalInitial) {
            return $this->naturallyAnsweredSourceFixture($actor, $session, $documents, $uploadMoment, $targetMode !== 'unique', $targetMode === 'selected');
        }
        $artifact = (new BuildMostEstimateDraft)->seal([
            'generation_contract' => UniversalDraftProjector::CONTRACT, 'source_info_only' => true, 'is_complete' => false,
            'evaluation_result' => null, 'local_estimates' => [], 'stage6_candidate_rows' => [],
            'scope_identity' => ['organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2]],
            'evaluation_policy' => ['mode' => 'universal', 'profile_id' => 'construction', 'selected_sections' => ['equipment']],
            'estimate_composition' => ['schema_version' => 1, 'snapshot_token' => $capture['token'], 'input_fingerprint' => str_repeat('b', 64),
                'request_info' => [['index' => 0, 'reason' => 'estimate_composer_work_scope_unresolved', 'work_name' => null,
                    'next_action' => 'provide_work_scope', 'source_fact_ids' => []]]],
        ]);
        $current = app(ResolveCurrentEstimateClarification::class)->resolveComposer($artifact, $input, $capture['snapshot'], $capture['token'], $scope, (int) $session->state_version);
        self::assertNotNull($current);
        $decision = app(UserEvaluationChoiceWriter::class)->writeComposerAnswer($actor, $session, $current, 'other',
            'Требуется установить оборудование согласно проекту.', 'source-claim-answer-'.Str::uuid(), 'decision:'.Str::uuid());
        $answer = $models->fact(...[...$scope, $decision->selectedFactId]);
        self::assertNotNull($answer);

        return [$actor, $session->fresh(), $answer, $documents];
    }

    private function naturalQuestionStages(User $actor, EstimateGenerationSession $session, string $operationId, RunEstimateComposer $composer, bool $technologyWork = false): array
    {
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->sole();
        $session->forceFill(['status' => 'generating', 'input_payload' => [...$session->input_payload,
            'generation_actor_type' => 'user', 'generation_actor_id' => $actor->id, 'generation_attempt_id' => $operationId,
            'generation_credit_operation_id' => $operationId, 'generation_evaluation_input_hash' => $operation->input_hash]])->save();
        $results = new Pipeline\Stages\StageResultFactory(new Pipeline\InMemoryPipelineArtifactStore, Pipeline\PipelineDefinitionGraph::standard());
        $analysis = (new \App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser)->parse($session->input_payload, []);
        $analysis['evaluation_policy'] = ['mode' => 'universal', 'profile_id' => 'construction', 'price_policy' => 'normative', 'selected_sections' => $session->input_payload['selected_sections']];
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
        if ($technologyWork) {
            self::assertNotEmpty($planned->transientData['local_estimates']);
        } else {
            self::assertSame([], $planned->transientData['local_estimates']);
            self::assertNotEmpty($planned->transientData['estimate_composition']['request_info']);
        }
        $prices = ['regional_context' => [], 'supplementary_materials' => [], 'local_estimates' => $technologyWork ? $planned->transientData['local_estimates'] : []];
        $payloads[Pipeline\ProcessingStage::ResolvePrices->value] = $prices;
        $outputs[Pipeline\ProcessingStage::ResolvePrices->value] = $results->make($context(Pipeline\ProcessingStage::ResolvePrices, $outputs), Pipeline\ProcessingStage::ResolvePrices, $prices)->output;
        $built = (new Pipeline\Stages\BuildDraftStage($results, quantityEvidence: app(Pipeline\AcceptedQuantityEvidenceVerifier::class)))
            ->execute($context(Pipeline\ProcessingStage::BuildDraft, $outputs));
        $payloads[Pipeline\ProcessingStage::BuildDraft->value] = $built->transientData;
        $outputs[Pipeline\ProcessingStage::BuildDraft->value] = $built->output;
        $validateContext = $context(Pipeline\ProcessingStage::ValidateDraft, $outputs);
        $auditCycle = app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle::class);
        if ($technologyWork) {
            $auditWire = $this->createMock(RerankWireClient::class);
            $auditWire->method('provider')->willReturn('timeweb');
            $auditWire->expects(self::once())->method('call')->willReturnCallback(fn (string $model, array $messages, array $options): array =>
                $this->composerWireResponse([(int) $session->organization_id, (int) $session->project_id, (int) $session->id],
                    $operationId, $options, ['findings' => []], $model, 'estimate_audit'));
            $auditCycle = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle(
                new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\RunEstimateAudit(app(AiRoleRunRepository::class),
                    new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\TimewebEstimateAuditModel($auditWire,
                        app(EloquentAiUsageStore::class), app(AiPriceSnapshotResolver::class), 'openai/gpt-6-luna', 2097152, 8192, 60), 'openai/gpt-6-luna'),
                app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposerCorrection::class));
        }
        $validated = (new Pipeline\Stages\ValidateDraftStage(
            app(\App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService::class),
            new \App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftReadinessProjector, $results,
            $auditCycle,
            app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInputFactory::class), models: app(ProjectModelRepository::class)))
            ->execute($validateContext);
        return [Pipeline\CheckpointClaim::acquired($validateContext, Pipeline\ProcessingStage::ValidateDraft, (string) Str::uuid()), $validated];
    }

    private function publishNaturalQuestion(User $actor, EstimateGenerationSession $session, array $documents, bool $multipleTargets = false): EstimateGenerationSession
    {
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        [$session, $operationId] = $this->paidReceipt(RunEstimateComposer::PROMPT_CONTRACT, [], true,
            [Organization::query()->findOrFail($scope[0]), Project::query()->findOrFail($scope[1]), $actor, $session], false,
            function (EstimateGenerationSession $current, string $operationId) use ($actor, $documents, $scope, $multipleTargets): int {
                $wire = $this->createMock(RerankWireClient::class);
                $wire->method('provider')->willReturn('timeweb');
                $wire->expects(self::once())->method('call')->willReturnCallback(function (string $model, array $messages, array $options) use ($operationId, $documents, $scope, $multipleTargets): array {
                    $payload = json_decode($messages[1]['content'], true, 512, JSON_THROW_ON_ERROR)['project'];
                    $hasAnswers = array_filter($payload['facts'], static fn (array $fact): bool => ($fact['context_kind'] ?? null) === 'composer_answer') !== [];
                    self::assertSame($hasAnswers, array_key_exists('claim_answer_fact_ids', $payload));
                    self::assertContains($documents[0]->id, array_column($payload['facts'], 'id'));
                    $work = static fn (Fact $document): array => [
                        'kind' => 'supplementary', 'candidate_id' => null, 'work_key' => 'custom.installation',
                        'name' => 'Монтаж неизвестной установки', 'work_section' => 'equipment', 'unit' => 'count',
                        'derived_quantity_id' => null, 'source_fact_ids' => [$document->id], 'entity_id' => $document->entityId,
                        'technology_package_candidate' => null, 'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => [],
                    ];
                    return $this->composerWireResponse($scope, $operationId, $options,
                        ['work_intents' => array_map($work, $multipleTargets ? $documents : [$documents[0]]),
                            ...($hasAnswers ? ['claim_candidates' => []] : [])], $model);
                });
                $composer = new RunEstimateComposer(app(AiRoleRunRepository::class),
                    new TimewebEstimateComposerModel($wire, app(EloquentAiUsageStore::class), app(AiPriceSnapshotResolver::class),
                        'openai/gpt-6-luna', 524288, 8192, 60), 'openai/gpt-6-luna', allowance: app(EstimateComposerAllowance::class));
                [$claim, $result] = $this->naturalQuestionStages($actor, $current, $operationId, $composer);
                self::assertSame('estimate_composer_work_definition_unverified', $result->transientData['draft']['estimate_composition']['request_info'][0]['reason']);
                DB::transaction(fn () => app(Pipeline\PublishValidatedDraft::class)->beforeComplete($claim, $result, new \DateTimeImmutable));
                return (int) DB::table('estimate_generation_ai_role_runs')->where('organization_id', $scope[0])
                    ->where('project_id', $scope[1])->where('session_id', $scope[2])->where('status', 'completed')
                    ->whereIn('physical_attempt_id', DB::table('estimate_generation_vision_physical_attempts')
                        ->where('credit_operation_id', $operationId)->where('credit_role', 'estimate_composition')->select('attempt_id'))->sole()->id;
            });
        $session = $session->fresh();
        self::assertSame('input_review_required', $session->status->value);
        self::assertSame('settled', DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->value('status'));
        return $session;
    }

    private function naturallyAnsweredSourceFixture(User $actor, EstimateGenerationSession $session, array $documents, ?string $uploadMoment = null, bool $multipleTargets = false, bool $selectTarget = false): array
    {
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $initial = array_values(array_filter($models->snapshot(...$scope)->facts,
            static fn (Fact $fact): bool => ($fact->value['kind'] ?? null) === 'initial_description'))[0];
        $original = get_object_vars($initial);
        $session = $this->publishNaturalQuestion($actor, $session, $documents, $multipleTargets);
        $evaluation = app(GetCurrentEvaluation::class)->handle($actor, $scope[1], $scope[2]);
        self::assertNull($evaluation['result']);
        self::assertCount($multipleTargets ? 2 : 1, $evaluation['request_info']);
        self::assertSame(0, EstimateGenerationLearningExample::query()->where('generation_session_id', $scope[2])->count());
        $project = Project::query()->findOrFail($scope[1]);
        $controller = app(EstimateGenerationQuestionController::class);
        $request = Request::create('/', 'GET');
        $request->setUserResolver(static fn (): User => $actor);
        $catalog = $controller->index($request, $project, $session);
        self::assertSame(200, $catalog->getStatusCode());
        $items = $catalog->getData(true)['data']['items'];
        self::assertCount(1, $items);
        $card = $items[0];
        if ($uploadMoment === 'before_answer') {
            [$session, $newSource] = $this->uploadIndependentSpecification($actor, $project, $session);
            self::assertContains($this->answerComposerCard($actor, $project, $session, $card), [404, 409]);
            $session = $this->publishNaturalQuestion($actor, $session, [$newSource, $documents[1]]);
            $catalog = $controller->index($request, $project, $session);
            self::assertSame(200, $catalog->getStatusCode());
            $items = $catalog->getData(true)['data']['items'];
            self::assertCount(1, $items);
            self::assertNotSame($card['answer_fingerprint'], $items[0]['answer_fingerprint']);
            $card = $items[0];
            $documents = [$newSource, $documents[1]];
        }
        if ($selectTarget) {
            self::assertEqualsCanonicalizing(array_column($documents, 'entityId'), array_column($card['target_options'], 'entity_id'));
            self::assertSame(422, $this->answerComposerCard($actor, $project, $session, $card, ['target_entity_id' => 'entity:forged']));
        }
        self::assertSame(200, $this->answerComposerCard($actor, $project, $session, $card, $selectTarget
            ? ['target_entity_id' => $documents[0]->entityId, 'idempotency_key' => 'selected-answer-'.$session->id] : []));
        $answers = array_values(array_filter($models->snapshot(...$scope)->facts,
            static fn (Fact $fact): bool => ($fact->value['kind'] ?? null) === 'composer_answer'));
        self::assertCount(1, $answers);
        self::assertSame('evaluation_answer', $answers[0]->type);
        self::assertSame('user_assumption', $answers[0]->origin);
        self::assertNull($answers[0]->unit);
        self::assertFalse($answers[0]->value['numeric_parameters_confirmed']);
        if ($multipleTargets) {
            foreach ($documents as $document) {
                self::assertContains($document->id, array_column($answers[0]->value['composer_context']['dependencies'], 'fact_id'));
            }
            $captured = app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null, $session->fresh()->input_payload['selected_sections'], 'universal', $session->fresh()->input_payload]);
            self::assertSame($selectTarget ? [$documents[0]->entityId] : [], array_column($captured->facts, null, 'id')[$answers[0]->id]['related_entity_ids']);
            if ($selectTarget) {
                self::assertSame($documents[0]->entityId, $answers[0]->value['target_entity_id']);
                $classifier = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\WorkIntentSourceClassifier;
                $work = ['work_key' => 'custom.installation', 'work_section' => 'equipment', 'source_fact_ids' => [$answers[0]->id]];
                self::assertSame($documents[0]->entityId, $classifier->binding($work, $captured->facts)['entity_id']);
                foreach ([$initial->entityId, $documents[1]->entityId] as $wrongTarget) {
                    try {
                        $classifier->binding([...$work, 'entity_id' => $wrongTarget], $captured->facts);
                        self::fail('A selected answer must not bind a different work entity.');
                    } catch (\InvalidArgumentException $exception) {
                        self::assertSame('estimate_composer_work_source_unrelated', $exception->getMessage());
                    }
                }
                foreach ([$documents[1]->id => $documents[1]->entityId, $initial->id => $initial->entityId] as $sourceId => $entityId) {
                    foreach ([[$answers[0]->id, $sourceId], [$sourceId, $answers[0]->id]] as $sources) {
                        $independent = $classifier->binding(['work_key' => 'equipment.install', 'work_section' => 'equipment',
                            'source_fact_ids' => $sources, 'entity_id' => $entityId], $captured->facts);
                        self::assertSame($entityId, $independent['entity_id']);
                        self::assertSame([$sourceId], $independent['basis_fact_ids']);
                    }
                }
                $candidate = ['source_answer_fact_id' => $answers[0]->id, 'entity_id' => $documents[1]->entityId,
                    'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => $documents[1]->value],
                    'unit' => null, 'document_fact_ids' => [$documents[1]->id]];
                self::assertFalse(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput::sourceClaimCandidate($candidate, $captured->facts, $captured->claimAnswerFactIds));
                $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
                self::assertNull(app(ComposerSourceClaimValidationService::class)->capture($scope, $snapshot,
                    $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]), $session->fresh()->input_payload,
                    $candidate, GeometryInventory::currentDocumentVersions($snapshot, false)));
            }
        }
        if ($uploadMoment === 'after_answer') {
            $saved = get_object_vars($answers[0]);
            [$session, $newSource] = $this->uploadIndependentSpecification($actor, $project, $session->fresh());
            self::assertSame($saved, get_object_vars($models->fact(...[...$scope, $answers[0]->id])));
            $documents = [$newSource, $documents[1]];
        }
        if ($uploadMoment !== null) {
            $captured = app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null, $session->input_payload['selected_sections'], 'universal', $session->input_payload]);
            $capturedAnswers = array_column($captured->facts, null, 'id');
            self::assertArrayHasKey($answers[0]->id, $capturedAnswers);
            self::assertSame($initial->entityId, $capturedAnswers[$answers[0]->id]['context_entity_id']);
            self::assertNotSame($initial->entityId, $documents[0]->entityId);
        }
        self::assertSame($original, get_object_vars($models->fact(...[...$scope, $initial->id])));
        self::assertSame($uploadMoment === 'before_answer' ? 2 : 1, DB::table('estimate_generation_ai_usage')->where('organization_id', $scope[0])->count());
        return [$actor, $session->fresh(), $answers[0], $documents];
    }

    private function answerComposerCard(User $actor, Project $project, EstimateGenerationSession $session, array $card, array $overrides = []): int
    {
        $request = AnswerEstimateClarificationRequest::create('/', 'POST', ['response' => 'other',
            'other' => 'Требуется установить оборудование согласно проекту.', 'idempotency_key' => 'natural-answer-'.Str::uuid(),
            'expected_source_version' => $card['source_version'], 'answer_fingerprint' => $card['answer_fingerprint'], ...$overrides]);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setUserResolver(static fn (): User => $actor);
        $route = new Route('POST', 'projects/{project}/questions/{question}/answer', []);
        $route->bind($request);
        $route->setParameter('project', $project);
        $request->setRouteResolver(static fn (): Route => $route);
        $request->validateResolved();
        $response = app(EstimateGenerationQuestionController::class)->answer($request, $project, $session, $card['code']);
        if ($response->getStatusCode() === 200 && isset($overrides['target_entity_id'])) {
            self::assertSame($overrides['target_entity_id'], $response->getData(true)['data']['source_locator']['target_entity_id']);
        }
        return $response->getStatusCode();
    }

    private function uploadIndependentSpecification(User $actor, Project $project, EstimateGenerationSession $session): array
    {
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $originals = array_column(array_map(static fn (Fact $fact): array => get_object_vars($fact), $models->snapshot(...$scope)->facts), null, 'id');
        $credits = app(EstimatorCreditOperations::class);
        $oldState = (int) $session->state_version;
        $oldOperation = (string) Str::uuid();
        $oldQuote = $credits->quote($actor, (int) $project->id, (int) $session->id, $oldState, $oldOperation, 'generation');
        (new \Tests\Support\EstimateGeneration\InMemoryS3HttpStorage)->install();
        foreach ([Documents\DocumentSourceManifestStorage::class, Documents\DocumentUnitContentReader::class,
            Documents\DocumentUnitDetector::class, Documents\DocumentUnitProcessor::class] as $service) {
            $this->app->forgetInstance($service);
        }
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Оборудование')->fromArray([
            ['entity_key', 'parameter', 'value', 'unit'],
            ['equipment:after', 'equipment_specification', 'Монтаж стендов', ''],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'most-after-question-');
        self::assertIsString($path);
        try {
            (new Xlsx($spreadsheet))->save($path);
            $digest = hash_file('sha256', $path);
            $request = \App\BusinessModules\Addons\EstimateGeneration\Http\Requests\UploadEstimateGenerationDocumentsRequest::create('/', 'POST',
                ['state_version' => $session->state_version, 'request_id' => (string) Str::uuid()], [],
                ['files' => [\Illuminate\Http\UploadedFile::fake()->createWithContent('after-question.xlsx', file_get_contents($path))]]);
            $request->setContainer($this->app)->setRedirector($this->app['redirect']);
            $request->setUserResolver(static fn (): User => $actor);
            $route = new Route('POST', 'projects/{project}/documents', []);
            $route->bind($request);
            $route->setParameter('project', $project);
            $request->setRouteResolver(static fn (): Route => $route);
            $request->validateResolved();
            $response = app(\App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationDocumentController::class)
                ->upload($request, $project, $session);
            self::assertSame(200, $response->getStatusCode(), $response->getContent());
            $document = $session->documents()->where('filename', 'after-question.xlsx')->sole();
            self::assertSame('sha256:'.$digest, $document->source_version);
            self::assertSame($digest, $document->checksum_sha256);
            self::assertSame('processing_documents', $session->fresh()->status->value);
            self::assertNull($session->fresh()->input_payload['generation_attempt_id']);
            $this->app->call([Queue::pushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob::class)->sole(), 'handle']);
            $this->app->call([Queue::pushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationUnitJob::class)->sole(), 'handle']);
            $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
            foreach ($originals as $id => $original) {
                self::assertSame($original, get_object_vars($models->fact(...[...$scope, $id])));
            }
            $facts = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->type === 'equipment_specification'
                && $fact->sourceVersion === $document->source_version));
            self::assertCount(1, $facts);
            self::assertSame('document', $facts[0]->origin);
            self::assertSame('confirmed', $facts[0]->status);
            self::assertNull($facts[0]->unit);
            self::assertSame([], $facts[0]->nativeNumericProofs);
            $proofs = array_values(array_filter($snapshot->evidence, static fn ($proof): bool => in_array($proof->id, $facts[0]->evidenceIds, true)));
            self::assertCount(1, $proofs);
            self::assertSame('document:'.$document->id, $proofs[0]->sourceArtifactId);
            self::assertSame('xlsx:sheet:Оборудование!C2', $proofs[0]->nativeReference);
            self::assertSame($document->source_version, $proofs[0]->sourceVersion);
            $entity = array_column($snapshot->entities, null, 'id')[$facts[0]->entityId];
            self::assertSame('equipment:after', $entity->attributes['properties']['source_entity_key']);
            self::assertContains($session->fresh()->status->value, ['ready_to_generate', 'input_review_required']);
            try {
                DB::transaction(fn () => $credits->begin($actor, $session->fresh(), $oldState, $oldOperation, $oldQuote['quote_id'], 'generation'));
                self::fail('The uploaded document reused the old approval.');
            } catch (\App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState) {
                self::assertGreaterThan($oldState, (int) $session->fresh()->state_version);
            }

            return [$session->fresh(), $facts[0]];
        } finally {
            $spreadsheet->disconnectWorksheets();
            unlink($path);
        }
    }

    private function composerWireResponse(array $scope, string $operationId, array $options, array $response, string $model, string $role = 'estimate_composition'): array
    {
        $attempt = $options['estimate_generation_attempt']['attempt_id'];
        DB::transaction(static function () use ($operationId, $attempt, $scope, $role): void {
            app(EstimatorCreditAttemptAdmission::class)->admit(...[$operationId, $attempt, ...$scope,
                $role, new AiCost('1.00000000', 'RUB', 'available')]);
            DB::table('estimate_generation_vision_physical_attempts')->where('attempt_id', $attempt)
                ->where('state', 'pre_wire')->update(['state' => 'wire_started', 'wire_started_at' => now()]);
        });
        return ['content' => json_encode($response, JSON_THROW_ON_ERROR), 'model' => $model,
            'usage_available' => true, 'input_tokens' => 1000, 'output_tokens' => 100];
    }

    private function publishBinarySource(EstimateGenerationSession $session, bool $numericSecond = false, bool $duplicate = false, bool $lengthOperand = false): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Оборудование')->fromArray([
            ['entity_key', 'parameter', 'value', 'unit'],
            ['equipment:first', 'equipment_specification', 'Монтаж стендов', ''],
            $numericSecond ? ['equipment:second', 'equipment_count', 2, 'count'] : ['equipment:second', 'equipment_specification', 'Монтаж стендов', ''],
        ]);
        if ($lengthOperand) {
            $spreadsheet->getActiveSheet()->fromArray([['quantity:clearance', 'quantity', '102', 'mm']], null, 'A4');
            $spreadsheet->getActiveSheet()->setCellValueExplicit('C4', '102', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        $path = tempnam(sys_get_temp_dir(), 'most-source-claim-');
        self::assertIsString($path);
        try {
            (new Xlsx($spreadsheet))->save($path);
            $spreadsheet->disconnectWorksheets();
            $version = 'sha256:'.hash_file('sha256', $path);
            $document = EstimateGenerationDocument::query()->create(['organization_id' => $session->organization_id,
                'project_id' => $session->project_id, 'session_id' => $session->id, 'user_id' => $session->user_id,
                'filename' => 'equipment.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'storage_path' => 'org-'.$session->organization_id.'/estimate-generation/source-claim/source.xlsx',
                'file_size_bytes' => filesize($path), 'source_version' => $version, 'checksum_sha256' => substr($version, 7)]);
            $storage = new class($path) implements Documents\DocumentSourceManifestStorage, Documents\DocumentUnitContentReader
            {
                private array $artifacts = [];

                public function __construct(private readonly string $path) {}

                public function open(EstimateGenerationDocument $document, string $sourceVersion): Documents\SeekableDocumentSource
                {
                    if ($sourceVersion !== 'sha256:'.hash_file('sha256', $this->path)) {
                        throw new \LogicException('source_claim_fixture_digest_changed');
                    }

                    return new Documents\SeekableDocumentSource(fopen($this->path, 'rb'), filesize($this->path));
                }

                public function put(EstimateGenerationDocument $document, string $sourceVersion, Documents\DocumentUnitType $type,
                    int $index, string $content, string $contentType = 'text/plain'): Documents\StoredDocumentArtifact
                {
                    $hash = 'sha256:'.hash('sha256', $content);
                    $key = 'org-'.$document->organization_id.'/estimate-generation/source-claim/'.substr($hash, 7);
                    $this->artifacts[$key] = $content;

                    return new Documents\StoredDocumentArtifact($key, strlen($content), $hash, $contentType);
                }

                public function read(Documents\DocumentUnitExecutionContext $context): string
                {
                    $content = $this->artifacts[$context->locator['artifact_path']];
                    if ($context->locator['artifact_sha256'] !== 'sha256:'.hash('sha256', $content)
                        || $context->locator['artifact_bytes'] !== strlen($content)) {
                        throw new \LogicException('source_claim_fixture_artifact_changed');
                    }

                    return $content;
                }
            };
            $this->app->instance(Documents\DocumentSourceManifestStorage::class, $storage);
            $this->app->instance(Documents\DocumentUnitContentReader::class, $storage);
            $this->app->forgetInstance(Documents\DocumentUnitDetector::class);
            $this->app->forgetInstance(Documents\DocumentUnitProcessor::class);
            if ($lengthOperand) {
                $this->app->forgetInstance(ComposerSourceClaimValidationService::class);
            }
            $snapshot = ['schema_version' => 2, 'models' => ['vision' => 'openai/gpt-6-luna', 'classification' => 'openai/gpt-6-luna', 'normative_matching' => 'openai/gpt-6-luna'],
                'limits' => ['max_files' => 10, 'max_pages_per_file' => 200, 'max_total_pages' => 1000],
                'timeouts' => ['vision' => 60, 'classification' => 60, 'normative_matching' => 60],
                'retries' => ['vision' => 1, 'classification' => 1, 'normative_matching' => 1],
                'confidence' => ['classification' => '0.7000', 'geometry' => '0.7000', 'normative_matching' => '0.7000'],
                'enabled_formats' => ['xlsx'], 'manual_review' => ['low_confidence' => true],
                'budgets' => ['daily' => '100.00', 'monthly' => '1000.00', 'currency' => 'RUB']];
            if (app(EstimateGenerationSettingsService::class)->currentSnapshot('global', null) === null) {
                app(EstimateGenerationSettingsService::class)->change((int) SystemAdmin::factory()->create()->id,
                    EstimateGenerationSettingsData::fromArray([
                        ...array_diff_key($snapshot, ['schema_version' => true]), 'scope' => 'global', 'organization_id' => null,
                        'expected_version' => 0, 'idempotency_key' => (string) Str::uuid(),
                    ]));
            }
            $documents = [$document];
            if ($duplicate) {
                $twin = $document->replicate();
                $twin->filename = 'equipment-twin.xlsx';
                $twin->storage_path .= '.twin';
                $twin->save();
                $documents[] = $twin;
            }
            foreach ($documents as $document) {
                (new Documents\DocumentFileAdmission)->stored($document, EffectiveEstimateGenerationSettings::fromRecord([
                    'snapshot_id' => 1, 'scope' => 'organization', 'organization_id' => (int) $session->organization_id, 'version' => 1,
                    'snapshot_hash' => SettingsSnapshotHash::calculate($snapshot), 'snapshot' => $snapshot,
                ], (int) $session->organization_id));
                $units = app(Documents\DocumentUnitDetector::class)->detect($document, $version);
                self::assertCount(1, $units);
                $unit = app(Documents\DocumentProcessingUnitStore::class)->create((int) $session->organization_id, (int) $session->project_id,
                    (int) $session->id, (int) $document->id, $units[0]);
                self::assertSame(Documents\DocumentProcessingUnitClaimStatus::Acquired, app(Documents\ProcessDocumentUnit::class)->handle($unit->id, $version)->status);
            }
        } finally {
            unlink($path);
        }
    }
}
