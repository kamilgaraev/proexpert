<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\CreateEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceMaterializer;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaim;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePriorOutputs;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PublishValidatedDraft;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\BuildDraftStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\PlanWorkItemsStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\StageResultFactory;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\ValidateDraftStage;
use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlanCompiler;
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationNotificationService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\InMemoryAiRoleRunRepository;

final class LegacySemanticPublicationPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    private ?string $creditOperationId = null;

    #[DataProvider('legacyDescriptions')]
    public function test_legacy_description_runs_existing_stages_and_publishes_without_inventing_financial_work(?string $name, string $section, bool $paid): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->createQuietly();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $input = ['evaluation_mode' => 'legacy', 'building_type' => 'bridge', 'description' => $name ?? 'Требуется уточнить технологию'];
        $session = app(CreateEstimateGenerationSession::class)->handle(['organization_id' => $organization->id,
            'project_id' => $project->id, 'user_id' => $actor->id, 'input_payload' => $input], $actor);
        if ($paid) {
            $entitlements = Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
            $entitlements->allows('hasModuleAccess')->andReturn(true);
            $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class, $entitlements);
            config(['ai-assistant-credits.enforce' => false, 'estimate-generation.credits.enabled' => true]);
            app(\App\Services\Credits\AICreditService::class)->grant($organization, 1000000, 'purchase', null, 'test:legacy-source-only');
            $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
            $this->creditOperationId = (string) Str::uuid();
            $quote = $operations->quote($actor, (int) $project->id, (int) $session->id, 0, $this->creditOperationId, 'generation');
            $source = 'sha256:'.str_repeat('b', 64);
            $entity = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity('room:quote-new',
                (int) $organization->id, (int) $project->id, (int) $session->id, $source, 'room', 'room:quote-new',
                ['semantic_type' => 'room', 'identity' => ['room_name' => 'Quote room']]);
            $newFact = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:quote-new',
                (int) $organization->id, (int) $project->id, (int) $session->id, $source, $entity->id, 'room_name',
                'Новый контекст после согласования', null, 0.0, 'user_assumption', 'confirmed', []);
            app(ProjectModelRepository::class)->saveSourceModel([$entity], [$newFact], []);
            try {
                DB::transaction(fn () => $operations->begin($actor, $session, 0, $this->creditOperationId, $quote['quote_id'], 'generation'));
                self::fail('The previous known context bypassed reapproval.');
            } catch (\DomainException $exception) {
                self::assertSame('estimator_known_context_changed', $exception->getMessage());
            }
            self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
            $this->creditOperationId = (string) Str::uuid();
            $quote = $operations->quote($actor, (int) $project->id, (int) $session->id, 0, $this->creditOperationId, 'generation');
            DB::transaction(fn () => $operations->begin($actor, $session, 0, $this->creditOperationId, $quote['quote_id'], 'generation'));
            DB::transaction(fn () => $operations->begin($actor, $session, 0, $this->creditOperationId, $quote['quote_id'], 'generation'));
            self::assertSame(1, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        } elseif ($name === null) {
            DB::table('estimate_generation_ai_estimate_quota_reservations')->insert([
                'organization_id' => $organization->id, 'session_id' => $session->id,
                'monthly_period' => now()->startOfMonth()->toDateString(), 'status' => 'reserved', 'reserved_at' => now(),
            ]);
        }
        $attempt = (string) Str::uuid();
        $session->forceFill(['status' => 'generating', 'state_version' => 4,
            'input_payload' => [...$input, 'generation_attempt_id' => $attempt]])->save();
        $fact = app(ProjectModelRepository::class)->snapshot((int) $organization->id, (int) $project->id, (int) $session->id)->facts[0];
        $model = $this->createMock(EstimateComposerModel::class);
        $model->expects(self::once())->method('compose')->willReturnCallback(function (EstimateComposerInput $captured, callable $reserved) use ($name, $section, $fact): array {
            self::assertSame(EstimateComposerInput::WORK_SECTIONS, $captured->allowedWorkSections);
            self::assertArrayNotHasKey('declared_work_sections', array_column($captured->facts, null, 'id')[$fact->id]);
            $reserved((string) Str::uuid());

            return ['work_intents' => $name === null ? [] : [[
                'kind' => 'supplementary', 'candidate_id' => null, 'work_key' => 'custom.request', 'name' => $name,
                'work_section' => $section, 'unit' => 'шт', 'derived_quantity_id' => null, 'source_fact_ids' => [$fact->id],
                'technology_package_candidate' => null, 'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => [],
            ]]];
        });
        $runs = new InMemoryAiRoleRunRepository;
        $composer = new RunEstimateComposer($runs, $model, 'openai/gpt-6-luna',
            allowance: $paid ? app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerAllowance::class) : null);
        $results = new StageResultFactory(new InMemoryPipelineArtifactStore, PipelineDefinitionGraph::standard());
        $analysis = (new ConstructionSemanticParser)->parse($input, []);
        $analysis['evaluation_policy'] = ['mode' => 'legacy', 'price_policy' => 'normative', 'profile_id' => 'construction', 'selected_sections' => []];
        $payloads = [ProcessingStage::UnderstandDocuments->value => ['base_input_version' => 'sha256:'.str_repeat('a', 64),
                'documents' => [], 'documents_count' => 0, 'rebuild_section_key' => null],
            ProcessingStage::UnderstandObject->value => ['analysis' => $analysis],
            ProcessingStage::ExtractQuantities->value => ['quantity_learning_hints' => [], 'building_quantities' => [],
                'quantity_coverage_warnings' => [], 'stage6_generation_context' => []]];
        $planStage = new PlanWorkItemsStage(app(WorkPlanCompiler::class), $results, app(AcceptedQuantityEvidenceMaterializer::class),
            $composer, new EstimateComposerInputFactory(app(ProjectModelRepository::class), 10000));
        $planContext = $this->context($session, ProcessingStage::PlanWorkItems, $payloads, $results);
        $plan = $planStage->execute($planContext)->transientData;
        self::assertSame($plan, $planStage->execute($planContext)->transientData);
        self::assertSame([], $session->input_payload['selected_sections'] ?? []);
        $payloads[ProcessingStage::PlanWorkItems->value] = $plan;
        $payloads[ProcessingStage::ResolvePrices->value] = ['regional_context' => [], 'supplementary_materials' => [], 'local_estimates' => $plan['local_estimates']];
        $built = (new BuildDraftStage($results))->execute($this->context($session, ProcessingStage::BuildDraft, $payloads, $results));
        $draft = $built->transientData['draft'];
        $payloads[ProcessingStage::BuildDraft->value] = ['draft' => $draft];
        $context = $this->context($session, ProcessingStage::ValidateDraft, $payloads, $results);
        $validated = (new ValidateDraftStage(app(\App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService::class),
            new \App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftReadinessProjector, $results,
            app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle::class),
            app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInputFactory::class)))->execute($context);
        self::assertTrue($validated->transientData['requires_review']);
        self::assertArrayNotHasKey('independent_audit', $validated->transientData['draft']);
        if ($name !== null) {
            self::assertCount(1, $draft['stage6_candidate_rows']);
            self::assertSame($name, $draft['stage6_candidate_rows'][0]['name']);
            self::assertNull($draft['stage6_candidate_rows'][0]['unit']);
            self::assertNull($draft['stage6_candidate_rows'][0]['total_cost']);
        } else {
            self::assertTrue($validated->transientData['draft']['source_info_only']);
            self::assertNull($validated->transientData['draft']['evaluation_result']);
        }
        $notifications = $this->createMock(EstimateGenerationNotificationService::class);
        $notifications->method('notifyFinished')->willReturn(true);
        $this->app->instance(EstimateGenerationNotificationService::class, $notifications);
        $publish = fn () => app(PublishValidatedDraft::class)->beforeComplete(
            CheckpointClaim::acquired($context, ProcessingStage::ValidateDraft, (string) Str::uuid()), $validated, new DateTimeImmutable);
        if ($name === null) {
            $this->assertSourceOnlyPublicationGuards($session, $context, $validated, $results);
            try {
                DB::transaction(function () use ($publish): void {
                    $publish();
                    throw new \RuntimeException('rollback_legacy_source_only');
                });
                self::fail('Publication rollback did not occur.');
            } catch (\RuntimeException $exception) {
                self::assertSame('rollback_legacy_source_only', $exception->getMessage());
            }
            self::assertSame(EstimateGenerationStatus::Generating, $session->fresh()->status);
            if ($paid) {
                self::assertSame('reserved', DB::table('estimate_generation_credit_operations')->where('operation_id', $this->creditOperationId)->value('status'));
            }
        }
        DB::transaction($publish);
        $published = $session->fresh();
        self::assertSame($name === null ? EstimateGenerationStatus::InputReviewRequired : EstimateGenerationStatus::EstimateReviewRequired, $published->status);
        self::assertFalse($published->draft_payload['is_complete']);
        self::assertSame(0, $published->packages()->count());
        self::assertSame(0, DB::table('estimate_generation_package_items')->count());
        self::assertSame(0, DB::table('estimate_generation_evaluation_revisions')->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        if ($name === null) {
            $current = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetCurrentEvaluation::class)
                ->handle($actor, (int) $project->id, (int) $session->id);
            self::assertTrue($current['source_info_required']);
            self::assertNull($current['result']);
            self::assertNull($current['revision']);
            self::assertSame('legacy', $current['evaluation_mode']);
            try {
                DB::transaction($publish);
                self::fail('A stale publication was replayed.');
            } catch (\App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState) {
                self::assertSame(5, (int) $session->fresh()->state_version);
            }
            if ($paid) {
                $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $this->creditOperationId)->sole();
                self::assertSame('settled', $operation->status);
                self::assertSame(0, (int) $operation->consumed_units_minor);
                self::assertNull($operation->result_revision_id);
                $receipt = DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)
                    ->publish($published, $this->creditOperationId, $validated->transientData['draft']));
                self::assertSame(0, $receipt['consumed_units_minor']);
                self::assertSame(1000000, app(\App\Services\Credits\AICreditService::class)->balance($organization)['available_minor']);
            } else {
                self::assertSame('released', DB::table('estimate_generation_ai_estimate_quota_reservations')->where('session_id', $session->id)->value('status'));
            }
        }
        Http::assertNothingSent();
    }

    public static function legacyDescriptions(): iterable
    {
        yield 'stands' => ['Монтаж стендов', 'equipment', false];
        yield 'foundation' => ['Устройство фундамента', 'structures', false];
        yield 'ambiguous reserved wallet' => [null, 'equipment', true];
        yield 'ambiguous legacy slot' => [null, 'equipment', false];
    }

    private function context(EstimateGenerationSession $session, ProcessingStage $stage, array $payloads, StageResultFactory $results): PipelineContext
    {
        $base = 'sha256:'.str_repeat('a', 64);
        $graph = PipelineDefinitionGraph::standard();
        $outputs = [];
        foreach ($payloads as $key => $payload) {
            $prior = ProcessingStage::from($key);
            $versions = array_fill_keys(array_map(static fn (ProcessingStage $dependency): string => $dependency->value, $graph->get($prior)->dependencies), $base);
            $outputs[$key] = $results->make(new PipelineContext((int) $session->id, (int) $session->organization_id, (int) $session->project_id,
                4, $base, 'generating', generationAttemptId: $session->input_payload['generation_attempt_id'], baseInputVersion: $base,
                stage: $prior, dependencyVersions: $versions), $prior, $payload)->output;
        }
        $versions = [];
        foreach ($graph->get($stage)->dependencies as $dependency) {
            $versions[$dependency->value] = $outputs[$dependency->value]->version;
        }

        return new PipelineContext((int) $session->id, (int) $session->organization_id, (int) $session->project_id, 4, $base, 'generating',
            priorOutputs: new PipelinePriorOutputs($outputs, $payloads), generationAttemptId: $session->input_payload['generation_attempt_id'],
            baseInputVersion: $base, stage: $stage, dependencyVersions: $versions, creditOperationId: $this->creditOperationId);
    }

    private function assertSourceOnlyPublicationGuards(EstimateGenerationSession $session, PipelineContext $context,
        \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult $validated, StageResultFactory $results): void
    {
        $draft = $validated->transientData['draft'];
        foreach ([['source_info_only' => false], ['is_complete' => true], ['evaluation_result' => []],
            ['stage6_candidate_rows' => [['key' => 'candidate']]], ['local_estimates' => [['sections' => [['work_items' => [['key' => 'work']]]]]]],
            ['estimate_composition' => ['request_info' => []]], ['scope_identity' => ['organization_id' => (int) $session->organization_id + 1,
                'project_id' => (int) $session->project_id, 'session_id' => (int) $session->id]], ['quality_summary' => []]] as $override) {
            $forged = (new \App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft)->seal([...$draft, ...$override]);
            $result = $results->make($context, ProcessingStage::ValidateDraft, ['draft' => $forged, 'requires_review' => true]);
            try {
                DB::transaction(fn () => app(PublishValidatedDraft::class)->beforeComplete(
                    CheckpointClaim::acquired($context, ProcessingStage::ValidateDraft, (string) Str::uuid()), $result, new DateTimeImmutable));
                self::fail('A forged source-only result was published.');
            } catch (\DomainException $exception) {
                self::assertSame('legacy_source_info_publication_incomplete', $exception->getMessage());
            }
        }
        $tampered = [...$draft, 'title' => 'Changed after artifact sealing'];
        $result = $results->make($context, ProcessingStage::ValidateDraft, ['draft' => $tampered, 'requires_review' => true]);
        try {
            DB::transaction(fn () => app(PublishValidatedDraft::class)->beforeComplete(
                CheckpointClaim::acquired($context, ProcessingStage::ValidateDraft, (string) Str::uuid()), $result, new DateTimeImmutable));
            self::fail('A tampered artifact was published.');
        } catch (\DomainException $exception) {
            self::assertSame('legacy_source_info_publication_incomplete', $exception->getMessage());
        }
        foreach (['organization', 'state', 'attempt', 'source'] as $changed) {
            $stale = new PipelineContext((int) $session->id, (int) $session->organization_id + ($changed === 'organization' ? 1 : 0),
                (int) $session->project_id, $changed === 'state' ? 3 : 4, $context->inputVersion, 'generating',
                generationAttemptId: $changed === 'attempt' ? (string) Str::uuid() : $context->generationAttemptId,
                baseInputVersion: $changed === 'source' ? 'sha256:'.str_repeat('b', 64) : $context->baseInputVersion,
                creditOperationId: $this->creditOperationId);
            try {
                DB::transaction(fn () => app(PublishValidatedDraft::class)->beforeComplete(
                    CheckpointClaim::acquired($stale, ProcessingStage::ValidateDraft, (string) Str::uuid()), $validated, new DateTimeImmutable));
                self::fail('A stale or foreign result was published.');
            } catch (\App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState) {
                self::assertSame(EstimateGenerationStatus::Generating, $session->fresh()->status);
            }
        }
    }
}
