<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaim;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePriorOutputs;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PublishValidatedDraft;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\StageResultFactory;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\ValidateDraftStage;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationNotificationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftReadinessProjector;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class CandidateOnlyDraftPublicationPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    #[DataProvider('reservationCases')]
    public function test_candidates_survive_validation_and_publication_without_ordinary_items_or_legacy_quota_use(?string $reservationStatus, ?string $expectedStatus): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->createQuietly();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $attempt = (string) Str::uuid();
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $actor->id,
            'status' => 'generating', 'state_version' => 4, 'input_payload' => ['generation_attempt_id' => $attempt],
        ]);
        if ($reservationStatus !== null) {
            DB::table('estimate_generation_ai_estimate_quota_reservations')->insert([
                'organization_id' => $organization->id, 'session_id' => $session->id,
                'monthly_period' => now()->startOfMonth()->toDateString(), 'status' => $reservationStatus, 'reserved_at' => now(),
                'confirmed_at' => $reservationStatus === 'confirmed' ? now() : null,
                'released_at' => $reservationStatus === 'released' ? now() : null,
            ]);
        }
        $candidate = ['key' => 'unaccepted-work', 'name' => 'Устройство покрытия', 'quantity' => null, 'total_cost' => null];
        $base = 'sha256:'.str_repeat('a', 64);
        $draft = (new BuildMostEstimateDraft)->seal([
            'generation_contract' => BuildMostEstimateDraft::CONTRACT,
            'source_input_version' => $base,
            'local_estimates' => [['key' => 'finishing', 'title' => 'Отделка', 'source_refs' => [],
                'sections' => [['key' => 'floor', 'title' => 'Полы', 'work_items' => []]]]],
            'stage6_candidate_rows' => [$candidate],
            'stage6_review_items' => [['code' => 'normative_not_found', 'message' => 'Подтвердите расценку.']],
            'is_complete' => true, 'stage6_status' => 'ready',
            'independent_audit' => ['status' => 'accepted'],
        ]);
        $graph = PipelineDefinitionGraph::standard();
        $results = new StageResultFactory(new InMemoryPipelineArtifactStore, $graph);
        $dependencies = [];
        foreach ($graph->get(ProcessingStage::BuildDraft)->dependencies as $dependency) {
            $dependencies[$dependency->value] = $base;
        }
        $build = $results->make(new PipelineContext(
            (int) $session->id, (int) $organization->id, (int) $project->id, 4, $base, 'generating',
            generationAttemptId: $attempt, baseInputVersion: $base, stage: ProcessingStage::BuildDraft,
            dependencyVersions: $dependencies,
        ), ProcessingStage::BuildDraft, ['draft' => $draft]);
        self::assertNotNull($build->output);
        $context = new PipelineContext(
            (int) $session->id, (int) $organization->id, (int) $project->id, 4, $base, 'generating',
            priorOutputs: new PipelinePriorOutputs([ProcessingStage::BuildDraft->value => $build->output],
                [ProcessingStage::BuildDraft->value => ['draft' => $draft]]),
            generationAttemptId: $attempt, baseInputVersion: $base, stage: ProcessingStage::ValidateDraft,
            dependencyVersions: [ProcessingStage::BuildDraft->value => $build->output->version],
        );
        $result = (new ValidateDraftStage(app(EstimateValidationService::class), new DraftReadinessProjector, $results,
            app(ApplyComposerCorrectionCycle::class), app(EstimateAuditInputFactory::class)))->execute($context);
        self::assertTrue($result->transientData['requires_review']);
        self::assertArrayNotHasKey('independent_audit', $result->transientData['draft']);
        $notifications = $this->createMock(EstimateGenerationNotificationService::class);
        $notifications->expects(self::once())->method('notifyFinished')->willReturn(true);
        $this->app->instance(EstimateGenerationNotificationService::class, $notifications);
        DB::transaction(fn () => app(PublishValidatedDraft::class)->beforeComplete(
            CheckpointClaim::acquired($context, ProcessingStage::ValidateDraft, (string) Str::uuid()),
            $result, new DateTimeImmutable,
        ));
        $published = $session->fresh();
        self::assertSame(EstimateGenerationStatus::EstimateReviewRequired, $published->status);
        self::assertSame(5, (int) $published->state_version);
        self::assertSame([$candidate], $published->draft_payload['stage6_candidate_rows']);
        self::assertFalse($published->draft_payload['is_complete']);
        self::assertSame('review_required', $published->draft_payload['stage6_status']);
        self::assertTrue((new BuildMostEstimateDraft)->verifyArtifact($published->draft_payload));
        self::assertSame(0, $published->packages()->count());
        self::assertSame(0, DB::table('estimate_generation_package_items')->count());
        app(\App\BusinessModules\Addons\EstimateGeneration\Services\Billing\AiEstimateQuotaService::class)->releaseCandidateOnlyReservation($published);
        self::assertSame($expectedStatus, DB::table('estimate_generation_ai_estimate_quota_reservations')
            ->where('session_id', $session->id)->value('status'));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        Http::assertNothingSent();
    }

    public static function reservationCases(): array
    {
        return ['unused' => ['reserved', 'released'], 'already_used' => ['confirmed', 'confirmed'],
            'already_released' => ['released', 'released'], 'no_reservation' => [null, null]];
    }
}
