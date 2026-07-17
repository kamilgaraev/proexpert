<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\HandleDocumentProcessingFailure;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessEstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

final class ProcessEstimateGenerationDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const CONNECTION = 'redis_estimate_generation';

    public const QUEUE = 'estimate-generation-documents';

    public const RECOVERY_QUEUE = 'estimate-generation-documents-recovery';

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(
        private readonly int $documentId,
        private readonly FailureExecutionSnapshot $failureSnapshot,
    ) {
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->overlapKey()))
                ->releaseAfter(15)
                ->expireAfter($this->timeout + 60),
            new RateLimited('estimate-generation-ocr-documents'),
        ];
    }

    public function overlapKey(): string
    {
        return 'estimate-generation:document-dispatch:'.$this->documentId.':'.$this->failureSnapshot->attemptId;
    }

    public function rateLimitKey(): string
    {
        return 'document:'.$this->documentId;
    }

    public function handle(ProcessEstimateGenerationDocument $documents): void
    {
        $documents->handle($this->documentId, $this->failureSnapshot);
    }

    public function failed(Throwable $error): void
    {
        if ($error instanceof StaleEstimateGenerationState) {
            return;
        }

        app(HandleDocumentProcessingFailure::class)->handle(
            $this->documentId,
            $this->failureSnapshot,
            $error,
            $this->attempts(),
        );
    }
}
