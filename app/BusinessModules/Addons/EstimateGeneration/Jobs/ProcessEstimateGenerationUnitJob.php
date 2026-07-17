<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitClaimStatus;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

final class ProcessEstimateGenerationUnitJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const CONNECTION = 'redis_estimate_generation';

    public const QUEUE = 'estimate-generation-units';

    public const RECOVERY_QUEUE = 'estimate-generation-units-recovery';

    public int $tries = 20;

    public int $timeout = 1800;

    public array $backoff = [30, 120];

    public bool $failOnTimeout = true;

    public function __construct(
        private readonly int $unitId,
        private readonly string $sourceVersion,
    ) {
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('estimate-generation:unit:'.$this->unitId))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 120),
            new RateLimited('estimate-generation-document-units'),
        ];
    }

    public function rateLimitKey(): string
    {
        return 'unit:'.$this->unitId;
    }

    public function handle(ProcessDocumentUnit $processor): void
    {
        $outcome = $processor->handle($this->unitId, $this->sourceVersion);

        if ($outcome->status === DocumentProcessingUnitClaimStatus::Busy && $outcome->retryAt !== null) {
            $this->release(max(1, now()->diffInSeconds($outcome->retryAt, false)));
        }
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function failed(\Throwable $error): void
    {
        Log::error('[EstimateGeneration] Unit job exhausted retries', [
            'unit_id' => $this->unitId,
            'failure_fingerprint' => hash('sha256', $error::class),
        ]);

        RecoverEstimateGenerationUnitsJob::dispatch()->delay(now()->addMinute());
    }
}
