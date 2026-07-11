<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\GenerationAttemptGuard;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationNotificationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationOrchestrator;
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
        private readonly ?int $expectedStateVersion = null,
        private readonly ?string $attemptId = null,
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
        EstimateGenerationOrchestrator $orchestrator,
        ?EstimateGenerationNotificationService $notificationService = null,
        ?GenerationAttemptGuard $attemptGuard = null,
    ): void {
        $session = EstimateGenerationSession::query()->find($this->sessionId);

        if (! $session instanceof EstimateGenerationSession) {
            return;
        }

        $attemptGuard ??= app(GenerationAttemptGuard::class);
        if (! $attemptGuard->matches($session, $this->expectedStateVersion, $this->attemptId)) {
            return;
        }

        $generatedSession = $orchestrator->generate($session);
        $notificationService ??= app(EstimateGenerationNotificationService::class);
        $notificationService->notifyFinished($generatedSession);
    }

    public function failed(\Throwable $exception): void
    {
        $session = EstimateGenerationSession::query()->find($this->sessionId);
        if ($session instanceof EstimateGenerationSession
            && app(GenerationAttemptGuard::class)->matches($session, null, $this->attemptId)) {
            try {
                $session = app(AdvanceEstimateGeneration::class)->failed($session, $exception);
                app(EstimateGenerationNotificationService::class)->notifyFailed($session, $exception);
            } catch (\Throwable) {
            }
        }

        Log::error('[EstimateGeneration] Draft generation job failed', [
            'session_id' => $this->sessionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
