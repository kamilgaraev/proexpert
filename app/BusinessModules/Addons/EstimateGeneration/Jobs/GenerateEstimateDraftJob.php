<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureWorkflowHandler;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\DraftPipelineEntrypoint;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateEstimateDraftJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const CONNECTION = 'redis_estimate_generation';

    public const QUEUE = 'estimate-generation';

    public int $tries = 3;

    public int $timeout = 1800;

    public array $backoff = [60, 180];

    public bool $failOnTimeout = true;

    public function __construct(
        private readonly int $sessionId,
        private readonly int $expectedStateVersion,
        private readonly string $attemptId,
        private readonly FailureExecutionSnapshot $failureSnapshot,
    ) {
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('estimate-generation:draft:session:'.$this->sessionId))
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 300),
            (new WithoutOverlapping('estimate-generation:draft:'.$this->rateLimitKey()))
                ->shared()
                ->releaseAfter(120)
                ->expireAfter($this->timeout + 300),
            new RateLimited('estimate-generation-drafts'),
        ];
    }

    public function rateLimitKey(): string
    {
        $organizationId = EstimateGenerationSession::query()
            ->whereKey($this->sessionId)
            ->value('organization_id');

        return $organizationId !== null
            ? 'organization:'.(int) $organizationId
            : 'session:'.$this->sessionId;
    }

    public function handle(
        DraftPipelineEntrypoint $pipeline,
        ?EstimateGenerationNotificationService $notificationService = null,
    ): void {
        $generatedSession = $pipeline->run($this->failureSnapshot);
        if (! $generatedSession instanceof EstimateGenerationSession) {
            return;
        }
        $notificationService ??= app(EstimateGenerationNotificationService::class);
        $notificationService->notifyFinished($generatedSession);
    }

    public function failed(\Throwable $exception): void
    {
        $session = EstimateGenerationSession::query()->find($this->sessionId);
        $snapshot = $this->failureSnapshot;
        if ($session instanceof EstimateGenerationSession
            && (int) $session->state_version === $snapshot->stateVersion
            && $session->status->value === $snapshot->status
            && hash_equals($snapshot->attemptId, (string) ($session->input_payload['generation_attempt_id'] ?? ''))) {
            try {
                $failure = app(FailureRecorder::class)->capture($exception, new FailureContext(
                    organizationId: $snapshot->organizationId,
                    projectId: $snapshot->projectId,
                    sessionId: $snapshot->sessionId,
                    stage: ProcessingStage::BuildDraft,
                    operation: 'generate_draft',
                    attempt: max(1, $this->attempts()),
                    correlationId: $snapshot->correlationId,
                    eventId: $snapshot->eventId,
                    expectedSessionStateVersion: $snapshot->stateVersion,
                    expectedSessionStatus: $snapshot->status,
                ));
                app(FailureWorkflowHandler::class)->handle($failure, $snapshot->stateVersion);
                $failedSession = EstimateGenerationSession::query()->find($snapshot->sessionId);
                if ($failedSession instanceof EstimateGenerationSession && $failedSession->status->value === 'failed') {
                    app(EstimateGenerationNotificationService::class)->notifyFailed($failedSession);
                }
            } catch (\Throwable) {
            }
        }

        Log::error('[EstimateGeneration] Draft generation job failed', [
            'session_id' => $this->sessionId,
            'failure_code' => 'draft_generation_failed',
            'failure_fingerprint' => hash('sha256', $exception::class.'|'.(string) $exception->getCode()),
        ]);
    }
}
