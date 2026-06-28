<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

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

    private const TERMINAL_STATUSES = [
        'generated',
        'ready_for_review',
        'review_required',
        'blocked',
        'applied',
    ];

    public int $tries = 3;

    public int $timeout = 1800;

    public array $backoff = [60, 180];

    public bool $failOnTimeout = true;

    public function __construct(
        private readonly int $sessionId,
    ) {
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('estimate-generation:draft:session:' . $this->sessionId))
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 300),
            (new WithoutOverlapping('estimate-generation:draft:' . $this->rateLimitKey()))
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
            ? 'organization:' . (int) $organizationId
            : 'session:' . $this->sessionId;
    }

    public function handle(
        EstimateGenerationOrchestrator $orchestrator,
        ?EstimateGenerationNotificationService $notificationService = null
    ): void
    {
        $session = EstimateGenerationSession::query()->find($this->sessionId);

        if (!$session instanceof EstimateGenerationSession) {
            return;
        }

        if (in_array($session->status, self::TERMINAL_STATUSES, true)) {
            return;
        }

        $session->forceFill([
            'status' => 'processing',
            'processing_stage' => 'draft_generation',
            'processing_progress' => 45,
            'last_error' => null,
        ])->save();

        $generatedSession = $orchestrator->generate($session);
        $notificationService ??= app(EstimateGenerationNotificationService::class);
        $notificationService->notifyFinished($generatedSession);
    }

    public function failed(\Throwable $exception): void
    {
        $updated = EstimateGenerationSession::query()
            ->where('id', $this->sessionId)
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->update([
                'status' => 'failed',
                'processing_stage' => 'failed',
                'processing_progress' => 0,
                'last_error' => mb_substr($exception->getMessage(), 0, 500),
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            $session = EstimateGenerationSession::query()->find($this->sessionId);

            if ($session instanceof EstimateGenerationSession) {
                app(EstimateGenerationNotificationService::class)->notifyFailed($session, $exception);
            }
        }

        Log::error('[EstimateGeneration] Draft generation job failed', [
            'session_id' => $this->sessionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
