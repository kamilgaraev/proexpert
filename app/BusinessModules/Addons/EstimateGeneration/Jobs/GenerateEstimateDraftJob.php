<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\HandleEstimateGenerationDraftFailure;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RunEstimateGenerationDraft;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\Skip;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateEstimateDraftJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const CONNECTION = 'redis_estimate_generation';

    public const QUEUE = 'estimate-generation';

    public int $tries = 20;

    public int $maxExceptions = 3;

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
            Skip::when(fn (): bool => $this->isStale()),
            new RateLimited('estimate-generation-drafts'),
        ];
    }

    public function rateLimitKey(): string
    {
        return 'organization:'.$this->failureSnapshot->organizationId;
    }

    public function handle(RunEstimateGenerationDraft $generation): void
    {
        $generation->handle($this->failureSnapshot, $this->expectedStateVersion, $this->attemptId);
    }

    public function failed(Throwable $error): void
    {
        app(HandleEstimateGenerationDraftFailure::class)->handle($this->failureSnapshot, $error);
    }

    private function isStale(): bool
    {
        if ($this->expectedStateVersion !== $this->failureSnapshot->stateVersion
            || ! hash_equals($this->attemptId, $this->failureSnapshot->attemptId)) {
            return true;
        }

        $current = EstimateGenerationSession::query()->find($this->sessionId);

        return ! $current instanceof EstimateGenerationSession
            || (int) $current->state_version !== $this->expectedStateVersion
            || $current->status->value !== $this->failureSnapshot->status
            || ! hash_equals($this->attemptId, (string) ($current->input_payload['generation_attempt_id'] ?? ''));
    }
}
