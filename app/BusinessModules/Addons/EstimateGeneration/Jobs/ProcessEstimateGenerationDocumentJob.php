<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\CreateDocumentProcessingUnits;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureWorkflowHandler;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentProcessingStatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;

final class ProcessEstimateGenerationDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const CONNECTION = 'redis_estimate_generation';

    public const QUEUE = 'estimate-generation';

    public int $tries = 3;

    public int $timeout = 120;

    private readonly string $failureEventId;

    private readonly string $failureCorrelationId;

    public function __construct(private readonly int $documentId)
    {
        $this->failureEventId = (string) Str::uuid();
        $this->failureCorrelationId = AiOperationContext::deterministicId('document-manifest|'.$documentId);
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('estimate-generation:document-dispatch:'.$this->documentId))
                ->releaseAfter(15)
                ->expireAfter($this->timeout + 60),
            new RateLimited('estimate-generation-ocr-documents'),
        ];
    }

    public function rateLimitKey(): string
    {
        return 'document:'.$this->documentId;
    }

    public function handle(CreateDocumentProcessingUnits $creator): void
    {
        $document = EstimateGenerationDocument::query()->with('session')->find($this->documentId);

        if (! $document instanceof EstimateGenerationDocument || in_array($document->status, ['ready', 'ignored'], true)) {
            return;
        }

        $creator->handle($document);
    }

    public function failed(\Throwable $error): void
    {
        $document = EstimateGenerationDocument::query()->with('session')->find($this->documentId);

        if (! $document instanceof EstimateGenerationDocument || in_array($document->status, ['ready', 'ignored'], true)) {
            return;
        }

        $failure = app(FailureRecorder::class)->capture($error, new FailureContext(
            organizationId: (int) $document->organization_id,
            projectId: (int) $document->project_id,
            sessionId: (int) $document->session_id,
            stage: ProcessingStage::UnderstandDocuments,
            operation: 'create_units',
            attempt: max(1, $this->attempts()),
            correlationId: $this->failureCorrelationId,
            eventId: $this->failureEventId,
            expectedSessionStateVersion: $document->session instanceof EstimateGenerationSession ? (int) $document->session->state_version : null,
            expectedSessionStatus: $document->session instanceof EstimateGenerationSession ? $document->session->status->value : null,
            documentId: (int) $document->getKey(),
        ));
        try {
            app(DocumentProcessingStatusService::class)->markFailed(
                $document,
                $failure->code,
                'estimate_generation.ocr_provider_error',
                ['failure_fingerprint' => $failure->fingerprint],
            );
            app(FailureWorkflowHandler::class)->handle(
                $failure,
                $document->session instanceof EstimateGenerationSession
                    ? (int) $document->session->state_version
                    : null,
            );
        } catch (\Throwable) {
        }
    }
}
