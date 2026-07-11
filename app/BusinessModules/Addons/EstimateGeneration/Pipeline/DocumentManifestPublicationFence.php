<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPipelineCheckpoint;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Database\Connection;

final readonly class DocumentManifestPublicationFence
{
    public function __construct(private Connection $database) {}

    public function publish(CheckpointClaim $claim, callable $publication): mixed
    {
        return $this->database->transaction(function () use ($claim, $publication): mixed {
            $context = $claim->context;
            $checkpoint = EstimateGenerationPipelineCheckpoint::query()
                ->whereKey($claim->checkpointId)
                ->where('session_id', $context->sessionId)
                ->where('status', CheckpointStatus::Running->value)
                ->where('claim_token', $claim->claimToken)
                ->where('lease_expires_at', '>', now())
                ->lockForUpdate()
                ->first();
            $session = EstimateGenerationSession::query()
                ->whereKey($context->sessionId)
                ->where('organization_id', $context->organizationId)
                ->where('project_id', $context->projectId)
                ->where('state_version', $context->stateVersion)
                ->where('status', $context->sessionStatus)
                ->lockForUpdate()
                ->first();
            $document = EstimateGenerationDocument::query()
                ->whereKey($context->documentId)
                ->where('organization_id', $context->organizationId)
                ->where('project_id', $context->projectId)
                ->where('session_id', $context->sessionId)
                ->lockForUpdate()
                ->first();
            if (! $checkpoint instanceof EstimateGenerationPipelineCheckpoint
                || ! $session instanceof EstimateGenerationSession
                || ! $document instanceof EstimateGenerationDocument
                || DocumentSourceVersion::fromDocument($document) !== $context->sourceVersion) {
                throw new StaleEstimateGenerationState($context->sessionId, $context->stateVersion);
            }

            return $publication();
        }, 3);
    }
}
