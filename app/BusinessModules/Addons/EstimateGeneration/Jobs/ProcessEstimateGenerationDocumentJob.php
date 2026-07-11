<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\CreateDocumentProcessingUnits;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureWorkflowHandler;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaimStatus;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactReference;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineCheckpointStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineInputVersion;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageOutput;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentProcessingStatusService;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class ProcessEstimateGenerationDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const CONNECTION = 'redis_estimate_generation';

    public const QUEUE = 'estimate-generation';

    public int $tries = 3;

    public int $timeout = 120;

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

    public function handle(CreateDocumentProcessingUnits $creator, PipelineCheckpointStore $checkpoints): void
    {
        $document = EstimateGenerationDocument::query()->with('session')->find($this->documentId);

        if (! $document instanceof EstimateGenerationDocument || in_array($document->status, ['ready', 'ignored'], true)) {
            return;
        }

        $snapshot = $this->failureSnapshot;
        $now = new DateTimeImmutable;
        $definition = PipelineDefinitionGraph::standard()->get(ProcessingStage::UnderstandDocuments);
        $baseInputVersion = (string) $snapshot->sourceVersion;
        $inputVersion = PipelineInputVersion::for($definition, $baseInputVersion, []);
        $claim = $checkpoints->claim(
            new PipelineContext(
                sessionId: $snapshot->sessionId,
                organizationId: $snapshot->organizationId,
                projectId: $snapshot->projectId,
                stateVersion: $snapshot->stateVersion,
                inputVersion: $inputVersion,
                sessionStatus: $snapshot->status,
                documentId: $snapshot->documentId,
                sourceVersion: $snapshot->sourceVersion,
                generationAttemptId: $snapshot->attemptId,
                baseInputVersion: $baseInputVersion,
                stage: ProcessingStage::UnderstandDocuments,
                dependencyVersions: [],
            ),
            ProcessingStage::UnderstandDocuments,
            $now,
            $now->modify('+180 seconds'),
        );
        if ($claim->status !== CheckpointClaimStatus::Acquired) {
            return;
        }
        try {
            $creator->handleClaimed($document, $claim);
            $output = PipelineStageOutput::create(
                $definition,
                $inputVersion,
                [],
                new PipelineArtifactReference(
                    'document_manifest_v1',
                    'document/'.(int) $document->getKey().'/'.$snapshot->attemptId,
                    $baseInputVersion,
                    1,
                ),
            );
            if (! $checkpoints->complete($claim, new PipelineStageResult(
                ProcessingStage::UnderstandDocuments,
                $output->version,
                ['document_id' => (int) $document->getKey()],
                output: $output,
            ), new DateTimeImmutable)) {
                throw new \RuntimeException('estimate_generation.document_manifest_claim_lost');
            }
        } catch (\Throwable $error) {
            $checkpoints->fail($claim, $error, new DateTimeImmutable);
            throw $error;
        }
    }

    public function failed(\Throwable $error): void
    {
        $document = EstimateGenerationDocument::query()->with('session')->find($this->documentId);

        if (! $document instanceof EstimateGenerationDocument || in_array($document->status, ['ready', 'ignored'], true)) {
            return;
        }

        $snapshot = $this->failureSnapshot;
        if (! $document->session instanceof EstimateGenerationSession
            || (int) $document->session->state_version !== $snapshot->stateVersion
            || $document->session->status->value !== $snapshot->status
            || (int) $document->organization_id !== $snapshot->organizationId
            || (int) $document->project_id !== $snapshot->projectId
            || (int) $document->session_id !== $snapshot->sessionId) {
            return;
        }
        $failure = app(FailureRecorder::class)->capture($error, new FailureContext(
            organizationId: $snapshot->organizationId,
            projectId: $snapshot->projectId,
            sessionId: $snapshot->sessionId,
            stage: ProcessingStage::UnderstandDocuments,
            operation: 'create_units',
            attempt: max(1, $this->attempts()),
            correlationId: $snapshot->correlationId,
            eventId: $snapshot->eventId,
            expectedSessionStateVersion: $snapshot->stateVersion,
            expectedSessionStatus: $snapshot->status,
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
                $snapshot->stateVersion,
            );
        } catch (\Throwable) {
        }
    }
}
