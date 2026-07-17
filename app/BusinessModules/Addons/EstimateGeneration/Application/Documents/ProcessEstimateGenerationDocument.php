<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaimStatus;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactReference;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineCheckpointStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineInputVersion;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageOutput;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final readonly class ProcessEstimateGenerationDocument
{
    public function __construct(
        private CreateDocumentProcessingUnits $creator,
        private DispatchDocumentProcessingUnits $dispatcher,
        private PipelineCheckpointStore $checkpoints,
        private PipelineDefinitionGraph $definitions,
    ) {}

    public function handle(int $documentId, FailureExecutionSnapshot $snapshot): void
    {
        $document = EstimateGenerationDocument::query()->with('session')->find($documentId);
        if (! $document instanceof EstimateGenerationDocument || in_array($document->status, ['ready', 'ignored'], true)) {
            return;
        }

        $now = new DateTimeImmutable;
        $definition = $this->definitions->get(ProcessingStage::UnderstandDocuments);
        $baseInputVersion = (string) $snapshot->sourceVersion;
        $inputVersion = PipelineInputVersion::for($definition, $baseInputVersion, []);
        $claim = $this->checkpoints->claim(
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
        if ($claim->status === CheckpointClaimStatus::AlreadyCompleted) {
            $this->dispatcher->forDocument($documentId, $baseInputVersion);

            return;
        }
        if ($claim->status !== CheckpointClaimStatus::Acquired) {
            return;
        }

        try {
            $this->creator->handleClaimed($document, $claim);
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
            if (! $this->checkpoints->complete($claim, new PipelineStageResult(
                ProcessingStage::UnderstandDocuments,
                $output->version,
                ['document_id' => (int) $document->getKey()],
                output: $output,
            ), new DateTimeImmutable)) {
                throw new RuntimeException('estimate_generation.document_manifest_claim_lost');
            }
            $this->dispatcher->forDocument($documentId, $baseInputVersion);
        } catch (Throwable $error) {
            $this->checkpoints->fail($claim, $error, new DateTimeImmutable);
            throw $error;
        }
    }
}
