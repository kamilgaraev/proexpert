<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\GenerateEstimateGenerationRequest;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\DraftPipelineEntrypoint;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\DraftPipelineRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationNotificationService;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EstimateGenerationQueueTest extends TestCase
{
    public function test_generate_dispatches_estimate_generation_job_to_dedicated_queue(): void
    {
        Queue::fake();

        [$user, $project, $session] = $this->makeGenerationSession('ready_to_generate');
        $request = GenerateEstimateGenerationRequest::create('/generate', 'POST', [
            'state_version' => $session->state_version,
        ]);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setUserResolver(static fn (): User => $user);

        $response = app(EstimateGenerationController::class)->generate($request, $project, $session);
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('generating', $payload['data']['status']);
        $this->assertSame('generating', $payload['data']['processing_stage']);

        Queue::assertPushed(
            GenerateEstimateDraftJob::class,
            static fn (GenerateEstimateDraftJob $job): bool => $job->queue === GenerateEstimateDraftJob::QUEUE
                && $job->connection === GenerateEstimateDraftJob::CONNECTION
        );

        $this->assertDatabaseHas('estimate_generation_sessions', [
            'id' => $session->id,
            'status' => 'generating',
            'processing_stage' => 'generating',
            'processing_progress' => 40,
        ]);
    }

    public function test_generation_job_uses_long_running_queue_settings(): void
    {
        $job = new GenerateEstimateDraftJob(123, 1, '018f4a20-3f4c-7a11-8a22-123456789abc', new FailureExecutionSnapshot(
            1, 2, 123, 1, 'generating', '018f4a20-3f4c-7a11-8a22-123456789abc',
            '018f4a20-3f4c-7a11-8a22-123456789abd', '018f4a20-3f4c-7a11-8a22-123456789abe',
        ));
        $productionSupervisor = config('horizon.environments.production.supervisor-estimate-generation');
        $localSupervisor = config('horizon.environments.local.supervisor-estimate-generation');

        $this->assertSame(GenerateEstimateDraftJob::CONNECTION, $job->connection);
        $this->assertSame(GenerateEstimateDraftJob::QUEUE, $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertSame(1800, $job->timeout);
        $this->assertGreaterThan($job->timeout, config('queue.connections.redis_estimate_generation.retry_after'));
        $this->assertGreaterThanOrEqual($job->tries, $productionSupervisor['tries']);
        $this->assertGreaterThanOrEqual($job->timeout, $productionSupervisor['timeout']);
        $this->assertGreaterThanOrEqual($job->tries, $localSupervisor['tries']);
        $this->assertGreaterThanOrEqual($job->timeout, $localSupervisor['timeout']);
    }

    public function test_generation_job_marks_session_failed_when_generation_fails(): void
    {
        [, , $session] = $this->makeGenerationSession('generating');
        $job = new GenerateEstimateDraftJob($session->id, $session->state_version, 'test-attempt', $this->snapshot($session, 'test-attempt'));

        $job->failed(new RuntimeException(str_repeat('Ошибка генерации ', 50)));

        $session->refresh();

        $this->assertSame(EstimateGenerationStatus::Failed, $session->status);
        $this->assertSame('failed', $session->processing_stage);
        $this->assertSame(0, $session->processing_progress);
        $this->assertNull($session->last_error);
        $this->assertSame('unexpected_internal_failure', $session->failure_code);
    }

    public function test_generation_job_does_not_override_finished_session_when_stale_attempt_fails(): void
    {
        [, , $session] = $this->makeGenerationSession('ready_to_apply');
        $session->forceFill([
            'processing_stage' => 'validation_and_normalization',
            'processing_progress' => 100,
            'last_error' => null,
        ])->save();

        $job = new GenerateEstimateDraftJob($session->id, $session->state_version, 'test-attempt', $this->snapshot($session, 'test-attempt'));
        $job->failed(new RuntimeException('stale queue attempt'));

        $session->refresh();

        $this->assertSame(EstimateGenerationStatus::ReadyToApply, $session->status);
        $this->assertSame('validation_and_normalization', $session->processing_stage);
        $this->assertSame(100, $session->processing_progress);
        $this->assertNull($session->last_error);
        $this->assertNull($session->failure_code);
    }

    public function test_stale_attempt_failure_does_not_change_or_notify_the_newer_generation(): void
    {
        [, , $session] = $this->makeGenerationSession('generating');
        $session->forceFill(['input_payload' => [
            ...($session->input_payload ?? []),
            'generation_attempt_id' => 'new-attempt',
        ]])->save();

        $notifications = Mockery::mock(EstimateGenerationNotificationService::class);
        $notifications->shouldNotReceive('notifyFailed');
        $this->app->instance(EstimateGenerationNotificationService::class, $notifications);

        (new GenerateEstimateDraftJob($session->id, $session->state_version, 'old-attempt', $this->snapshot($session, 'old-attempt')))
            ->failed(new RuntimeException('late failure'));

        $session->refresh();
        $this->assertSame(EstimateGenerationStatus::Generating, $session->status);
        $this->assertNull($session->last_error);
        $this->assertNull($session->failure_code);
    }

    public function test_generation_job_skips_finished_session_instead_of_regenerating(): void
    {
        [, , $session] = $this->makeGenerationSession('ready_to_apply');
        $pipeline = Mockery::mock(DraftPipelineEntrypoint::class);
        $pipeline->shouldNotReceive('run');

        $job = new GenerateEstimateDraftJob($session->id, $session->state_version, 'test-attempt', $this->snapshot($session, 'test-attempt'));
        $job->handle($pipeline);

        $session->refresh();

        $this->assertSame(EstimateGenerationStatus::ReadyToApply, $session->status);
    }

    public function test_generation_job_notifies_user_when_generation_finishes(): void
    {
        [, , $session] = $this->makeGenerationSession('generating');
        $pipeline = Mockery::mock(DraftPipelineEntrypoint::class);
        $pipeline->shouldReceive('run')
            ->once()
            ->andReturnUsing(static function () use ($session): DraftPipelineRunResult {
                $session->forceFill([
                    'status' => 'ready_to_apply',
                    'processing_stage' => 'validation_and_normalization',
                    'processing_progress' => 100,
                ])->save();

                return new DraftPipelineRunResult(
                    $session->fresh(['documents']),
                    ProcessingStage::ValidateDraft,
                    false,
                    true,
                );
            });

        $notifications = Mockery::mock(EstimateGenerationNotificationService::class);
        $notifications->shouldReceive('notifyFinished')
            ->once()
            ->with(Mockery::on(static fn (EstimateGenerationSession $notifiedSession): bool => $notifiedSession->id === $session->id
                && $notifiedSession->status === EstimateGenerationStatus::ReadyToApply));

        $job = new GenerateEstimateDraftJob($session->id, $session->state_version, 'test-attempt', $this->snapshot($session, 'test-attempt'));
        $job->handle($pipeline, $notifications);
    }

    public function test_generation_job_notifies_user_when_generation_fails(): void
    {
        [, , $session] = $this->makeGenerationSession('generating');
        $notifications = Mockery::mock(EstimateGenerationNotificationService::class);
        $notifications->shouldReceive('notifyFailed')
            ->once()
            ->with(Mockery::on(static fn (EstimateGenerationSession $notifiedSession): bool => $notifiedSession->id === $session->id
                && $notifiedSession->status === EstimateGenerationStatus::Failed
                && $notifiedSession->failure_code === 'unexpected_internal_failure'));
        $this->app->instance(EstimateGenerationNotificationService::class, $notifications);

        $job = new GenerateEstimateDraftJob($session->id, $session->state_version, 'test-attempt', $this->snapshot($session, 'test-attempt'));
        $job->failed(new RuntimeException('generation failed'));
    }

    public function test_estimate_generation_notification_contains_admin_target_route(): void
    {
        [$user, $project, $session] = $this->makeGenerationSession('ready_to_apply');
        $notificationService = Mockery::mock(NotificationService::class);
        $notificationService->shouldReceive('send')
            ->once()
            ->with(
                Mockery::on(static fn (User $recipient): bool => $recipient->is($user)),
                'estimate_generation_completed',
                Mockery::on(static fn (array $data): bool => $data['target_route'] === "/projects/{$project->id}/estimates/ai-workspace/{$session->id}"
                    && $data['force_send'] === true
                    && $data['entity_type'] === 'estimate_generation_session'
                    && $data['entity_id'] === $session->id
                    && $data['project_id'] === $project->id
                    && ($data['actions'][0]['route'] ?? null) === $data['target_route']),
                'custom',
                'normal',
                ['in_app', 'websocket'],
                $session->organization_id
            )
            ->andReturn(new Notification);

        (new EstimateGenerationNotificationService($notificationService))->notifyFinished($session);
    }

    public function test_status_returns_lightweight_generation_state(): void
    {
        [$user, $project, $session] = $this->makeGenerationSession('generating');
        $session->forceFill([
            'processing_stage' => 'draft_generation',
            'processing_progress' => 45,
        ])->save();

        $request = Request::create('/status', 'GET');
        $request->setUserResolver(static fn (): User => $user);

        $response = app(EstimateGenerationController::class)->status($request, $project, $session);
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['success']);
        $this->assertSame($session->id, $payload['data']['id']);
        $this->assertSame('generating', $payload['data']['status']);
        $this->assertSame('draft_generation', $payload['data']['processing_stage']);
        $this->assertSame(45, $payload['data']['processing_progress']);
        $this->assertArrayNotHasKey('input', $payload['data']);
        $this->assertArrayNotHasKey('analysis', $payload['data']);
        $this->assertArrayNotHasKey('draft_payload', $payload['data']);
    }

    /**
     * @return array{0: User, 1: Project, 2: EstimateGenerationSession}
     */
    private function snapshot(EstimateGenerationSession $session, string $attemptId): FailureExecutionSnapshot
    {
        return FailureExecutionSnapshot::capture($session, 'generate_draft', $attemptId);
    }

    private function makeGenerationSession(string $status): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => $status,
            'processing_stage' => $status,
            'processing_progress' => 35,
            'input_payload' => [
                'description' => 'Монолитные работы жилого дома',
                'generation_attempt_id' => 'test-attempt',
            ],
            'analysis_payload' => [
                'detected_structure' => [],
            ],
            'problem_flags' => [],
        ]);

        return [$user, $project, $session];
    }
}
