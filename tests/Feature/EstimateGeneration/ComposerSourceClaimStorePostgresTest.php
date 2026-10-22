<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents as Documents;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\CreateEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\UserEvaluationChoiceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
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
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EloquentAiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorCreditAttemptAdmission;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Services\Credits\AICreditService;
use Illuminate\Database\QueryException;
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

    public function test_terminal_source_info_fixture_records_one_normless_claim_and_reader_rechecks_the_current_scoped_source(): void
    {
        [$actor, $session, $answer, $documents] = $this->answeredSourceFixture('  Монтаж неизвестной установки  ');
        $candidate = ['source_answer_fact_id' => $answer->id, 'entity_id' => $documents[0]->entityId,
            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => 'Монтаж стендов'],
            'unit' => null, 'document_fact_ids' => [$documents[0]->id]];
        $organization = Organization::query()->findOrFail($session->organization_id);
        $project = Project::query()->findOrFail($session->project_id);
        [$session, $operationId, $runId] = $this->paidReceipt('estimate-composer:answers:v2', [$candidate], true,
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
        $expected = $accepted ? [['run_id' => $runId, 'candidate' => $this->candidate()]] : [];
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
            'no-answer' => ['estimate-composer:v4', [$candidate], false],
            'model-verdict' => ['estimate-composer:answers:v2', [[...$candidate, 'passed' => true]], false],
            'non-array' => ['estimate-composer:answers:v2', 'not-an-array', false],
            'oversized' => ['estimate-composer:answers:v2', array_fill(0, 9, $candidate), false],
        ];
    }

    private function paidReceipt(string $contract, mixed $candidates, bool $measured = true, ?array $fixture = null, bool $settle = true): array
    {
        [$organization, $project, $actor, $session] = $fixture ?? $this->fixture();
        $entitlements = \Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $entitlements->allows('hasModuleAccess')->andReturn(true);
        $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class, $entitlements);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        config(['ai-assistant-credits.enforce' => false, 'estimate-generation.credits.enabled' => true, 'estimate-generation.live_qa.enabled' => false]);
        if (! $actor->organizations()->whereKey($organization->id)->exists()) {
            $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        }
        app(AICreditService::class)->grant($organization, 1000000, 'purchase', null, 'test:source-claim:'.$organization->id);
        $operationId = (string) Str::uuid();
        $operations = app(EstimatorCreditOperations::class);
        $quote = $operations->quote($actor, (int) $project->id, (int) $session->id, (int) $session->state_version, $operationId, 'generation');
        DB::transaction(fn () => $operations->begin($actor, $session, (int) $session->state_version, $operationId, $quote['quote_id'], 'generation'));
        $input = new AiRoleRunInput((int) $organization->id, (int) $project->id, (int) $session->id, null, null,
            'estimate_session', (string) $session->id, hash('sha256', $operationId), AiAnalysisRole::EstimateComposer,
            'openai/gpt-6-luna', $contract, hash('sha256', 'source-claim-input:'.$operationId));
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
        $runs->complete($claim->runId, $owner, new AiRoleRunResult(['schema_version' => 2, 'role' => 'estimate_composer',
            'work_intents' => [], 'quarantined_intents' => [], 'result_state' => 'ready', 'claim_candidates' => $candidates], $attempt));
        if ($settle) {
            DB::transaction(fn () => app(EstimatorCreditOperationSettlement::class)->publish($session, $operationId,
                ['source_input_version' => $operation->source_version, 'local_estimates' => []]));
        }

        return [$session, $operationId, $claim->runId];
    }

    private function terminalFixture(User $actor, EstimateGenerationSession $session, string $operationId): array
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

        return [Pipeline\CheckpointClaim::acquired($context, Pipeline\ProcessingStage::ValidateDraft, (string) Str::uuid()), $result];
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

    private function answeredSourceFixture(string $description = 'Монтаж неизвестной установки'): array
    {
        Http::fake();
        Http::preventStrayRequests();
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->createQuietly();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $authorization->allows('can')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $authorization);
        config(['estimate-generation.universal_enabled' => true]);
        $input = ['evaluation_mode' => 'universal', 'profile_id' => 'construction', 'building_type' => 'bridge',
            'selected_sections' => ['equipment'], 'generation_mode' => 'ai_assisted', 'description' => $description];
        $session = app(CreateEstimateGenerationSession::class)->handle(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'input_payload' => $input], $actor);
        $this->publishBinarySource($session);
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
        $documents = array_values(array_filter($capture['snapshot']->facts, static fn ($fact): bool => $fact->type === 'equipment_specification'));
        self::assertCount(2, $documents);
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

    private function publishBinarySource(EstimateGenerationSession $session): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Оборудование')->fromArray([
            ['entity_key', 'parameter', 'value', 'unit'],
            ['equipment:first', 'equipment_specification', 'Монтаж стендов', ''],
            ['equipment:second', 'equipment_specification', 'Монтаж стендов', ''],
        ]);
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
            (new Documents\DocumentFileAdmission)->stored($document, EffectiveEstimateGenerationSettings::fromRecord([
                'snapshot_id' => 1, 'scope' => 'organization', 'organization_id' => (int) $session->organization_id, 'version' => 1,
                'snapshot_hash' => SettingsSnapshotHash::calculate($snapshot), 'snapshot' => $snapshot,
            ], (int) $session->organization_id));
            $units = app(Documents\DocumentUnitDetector::class)->detect($document, $version);
            self::assertCount(1, $units);
            $unit = app(Documents\DocumentProcessingUnitStore::class)->create((int) $session->organization_id, (int) $session->project_id,
                (int) $session->id, (int) $document->id, $units[0]);
            self::assertSame(Documents\DocumentProcessingUnitClaimStatus::Acquired, app(Documents\ProcessDocumentUnit::class)->handle($unit->id, $version)->status);
        } finally {
            unlink($path);
        }
    }
}
