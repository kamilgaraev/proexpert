<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentPipelineCheckpointStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class GenerationRecoveryCeilingPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    use \Tests\Support\EstimateGeneration\SeedsEstimatorSettings;

    public function test_recovery_stops_exhausted_sessions_releases_only_unused_quota_and_preserves_a_usable_draft(): void
    {
        $this->seedEstimatorSettings();
        \Illuminate\Support\Facades\Queue::fake();
        foreach (['cron', 'queue'] as $delivery) {
            foreach ([false, true] as $usable) {
                $organization = Organization::factory()->create();
                $project = Project::factory()->for($organization)->create();
                $attempt = (string) Str::uuid();
                $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id,
                    'project_id' => $project->id, 'user_id' => User::factory()->create()->id, 'status' => 'generating', 'state_version' => 0,
                    'input_payload' => ['generation_attempt_id' => $attempt]]);
                $input = 'sha256:'.hash('sha256', 'recovery fixture');
                $context = new PipelineContext(sessionId: $session->id, organizationId: $organization->id, projectId: $project->id,
                    stateVersion: 0, inputVersion: $input, sessionStatus: 'generating', generationAttemptId: $attempt,
                    baseInputVersion: $input, stage: ProcessingStage::UnderstandDocuments);
                $quota = app(\App\BusinessModules\Addons\EstimateGeneration\Services\Billing\AiEstimateQuotaService::class);
                $quota->reserve($session);
                if ($usable) {
                    \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage::query()->create([
                        'session_id' => $session->id, 'input_version' => $input, 'key' => 'main', 'title' => 'Пригодный комплект',
                        'scope_type' => 'building', 'status' => 'ready_for_review',
                    ]);
                    $quota->confirmUsableDraft($session);
                }
                $store = new EloquentPipelineCheckpointStore(DB::connection());
                $now = new DateTimeImmutable;
                for ($number = 1; $number <= 3; $number++) {
                    $claim = $store->claim($context, ProcessingStage::UnderstandDocuments, $now, $now->modify('+1 minute'));
                    self::assertTrue($store->fail($claim,
                        new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageException(FailureCategory::Recoverable, 'storage_unavailable'), $now));
                }
                if ($delivery === 'queue') {
                    $entry = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\DraftPipelineEntrypoint::class);
                    $entry->expects(self::once())->method('run')->willThrowException(new \App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException(FailureCategory::Terminal, 'pipeline_stage_retry_exhausted'));
                    $snapshot = \App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot::capture($session, 'run_generation_stage', $attempt);
                    (new \App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RunEstimateGenerationDraft($entry))->handle($snapshot, 0, $attempt);
                } else {
                    self::assertSame(0, app(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RecoverEstimateGenerationPipelines::class)->handle());
                }
                $fresh = $session->fresh();
                self::assertSame($usable ? 'estimate_review_required' : 'failed', $fresh->status->value);
                self::assertSame('pipeline_stage_retry_exhausted', $fresh->failure_code);
                self::assertSame($usable ? 'confirmed' : 'released', DB::table('estimate_generation_ai_estimate_quota_reservations')->where('session_id', $session->id)->value('status'));
                self::assertSame(0, app(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RecoverEstimateGenerationPipelines::class)->handle());
                self::assertSame(3, (int) DB::table('estimate_generation_pipeline_checkpoints')->where('session_id', $session->id)->sum('attempt_count'));
            }
        }
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    public function test_three_failed_stage_claims_cannot_be_restarted_by_queue_recovery_or_changed_input(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $attempt = (string) Str::uuid();
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id,
            'project_id' => $project->id, 'user_id' => User::factory()->create()->id, 'status' => 'generating', 'state_version' => 0,
            'input_payload' => ['generation_attempt_id' => $attempt]]);
        $input = 'sha256:'.hash('sha256', 'recovery fixture');
        $context = new PipelineContext(sessionId: $session->id, organizationId: $organization->id, projectId: $project->id,
            stateVersion: 0, inputVersion: $input, sessionStatus: 'generating', generationAttemptId: $attempt,
            baseInputVersion: $input, stage: ProcessingStage::UnderstandDocuments);
        $store = new EloquentPipelineCheckpointStore(DB::connection());
        $now = new DateTimeImmutable;
        for ($number = 1; $number <= 3; $number++) {
            $claim = $store->claim($context, ProcessingStage::UnderstandDocuments, $now, $now->modify('+1 minute'));
            self::assertSame($number, $claim->attempt);
            self::assertTrue($store->fail($claim,
                new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageException(FailureCategory::Recoverable, 'storage_unavailable'), $now));
        }
        foreach ([$input, 'sha256:'.hash('sha256', 'changed recovery input')] as $version) {
            $next = new PipelineContext(sessionId: $session->id, organizationId: $organization->id, projectId: $project->id,
                stateVersion: 0, inputVersion: $version, sessionStatus: 'generating', generationAttemptId: $attempt,
                baseInputVersion: $input, stage: ProcessingStage::UnderstandDocuments);
            try {
                $store->claim($next, ProcessingStage::UnderstandDocuments, $now, $now->modify('+1 minute'));
                self::fail('A permanent recoverable stage obtained another execution claim.');
            } catch (\App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException $error) {
                self::assertSame(FailureCategory::Terminal, $error->category);
                self::assertSame('pipeline_stage_retry_exhausted', $error->safeCode);
            }
        }
        self::assertSame(3, (int) DB::table('estimate_generation_pipeline_checkpoints')->where('session_id', $session->id)->sum('attempt_count'));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_success_on_the_last_allowed_attempt_stays_replayable_without_another_claim(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $attempt = (string) Str::uuid();
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id,
            'project_id' => $project->id, 'user_id' => User::factory()->create()->id, 'status' => 'generating', 'state_version' => 0,
            'input_payload' => ['generation_attempt_id' => $attempt]]);
        $input = 'sha256:'.hash('sha256', 'transient fixture');
        $context = new PipelineContext(sessionId: $session->id, organizationId: $organization->id, projectId: $project->id,
            stateVersion: 0, inputVersion: $input, sessionStatus: 'generating', generationAttemptId: $attempt,
            baseInputVersion: $input, stage: ProcessingStage::UnderstandDocuments);
        $store = new EloquentPipelineCheckpointStore(DB::connection());
        $now = new DateTimeImmutable;
        for ($number = 1; $number <= 2; $number++) {
            $claim = $store->claim($context, ProcessingStage::UnderstandDocuments, $now, $now->modify('+1 minute'));
            self::assertTrue($store->fail($claim,
                new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageException(FailureCategory::Recoverable, 'storage_unavailable'), $now));
        }
        $last = $store->claim($context, ProcessingStage::UnderstandDocuments, $now, $now->modify('+1 minute'));
        $output = \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageOutput::create(
            \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph::standard()->get(ProcessingStage::UnderstandDocuments),
            $input, [], new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactReference(
                's3_json_v1', 'org-'.$organization->id.'/estimate-generation/sessions/'.$session->id.'/pipeline/'.hash('sha256', 'fixture').'.json',
                'sha256:'.hash('sha256', '{}'), 2));
        self::assertTrue($store->complete($last, new \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult(ProcessingStage::UnderstandDocuments, $output->version, [], output: $output), $now));
        self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaimStatus::AlreadyCompleted,
            $store->claim($context, ProcessingStage::UnderstandDocuments, $now, $now->modify('+1 minute'))->status);
        self::assertSame(3, (int) DB::table('estimate_generation_pipeline_checkpoints')->where('session_id', $session->id)->sum('attempt_count'));
    }
}
