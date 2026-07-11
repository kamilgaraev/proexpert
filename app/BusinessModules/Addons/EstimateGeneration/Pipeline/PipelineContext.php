<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PipelineContext
{
    public function __construct(
        public int $sessionId,
        public int $organizationId,
        public int $projectId,
        public int $stateVersion,
        public string $inputVersion,
        public string $sessionStatus,
        public ?int $documentId = null,
        public ?string $sourceVersion = null,
        public PipelinePriorOutputs $priorOutputs = new PipelinePriorOutputs,
        public ?string $generationAttemptId = null,
        public ?string $baseInputVersion = null,
        public ?ProcessingStage $stage = null,
        public array $dependencyVersions = [],
        public ?string $claimToken = null,
        public ?int $stageAttempt = null,
        public ?DateTimeImmutable $leaseExpiresAt = null,
    ) {
        if ($sessionId <= 0 || $organizationId <= 0 || $projectId <= 0) {
            throw new InvalidArgumentException('Pipeline identity values must be positive.');
        }

        if ($stateVersion < 0) {
            throw new InvalidArgumentException('Pipeline state version cannot be negative.');
        }

        PipelineVersionValidator::assertValid($inputVersion, 'input');
        if (preg_match('/\A[a-z][a-z0-9_]{0,39}\z/', $sessionStatus) !== 1) {
            throw new InvalidArgumentException('Pipeline session status is invalid.');
        }
        if (($documentId === null) !== ($sourceVersion === null) || ($documentId !== null && $documentId < 1)) {
            throw new InvalidArgumentException('Pipeline document fence is incomplete or invalid.');
        }
        if ($sourceVersion !== null) {
            PipelineVersionValidator::assertValid($sourceVersion, 'source');
        }
        if ($generationAttemptId !== null && preg_match('/\A[0-9a-f-]{36}\z/', $generationAttemptId) !== 1) {
            throw new InvalidArgumentException('Pipeline generation attempt must be a UUID.');
        }
        if ($baseInputVersion !== null) {
            PipelineVersionValidator::assertSha256($baseInputVersion, 'base input');
        }
        if ($stage !== null) {
            PipelineVersionValidator::assertSha256($inputVersion, 'stage input');
            $expectedDependencies = array_map(
                static fn (ProcessingStage $dependency): string => $dependency->value,
                PipelineDefinitionGraph::standard()->get($stage)->dependencies,
            );
            if (array_keys($dependencyVersions) !== $expectedDependencies) {
                throw new InvalidArgumentException('Pipeline context dependency manifest is invalid.');
            }
        }
        if (($claimToken === null) !== ($stageAttempt === null) || ($claimToken === null) !== ($leaseExpiresAt === null)) {
            throw new InvalidArgumentException('Pipeline physical claim context is incomplete.');
        }
    }

    public function withPriorOutputs(PipelinePriorOutputs $priorOutputs): self
    {
        return new self(
            $this->sessionId,
            $this->organizationId,
            $this->projectId,
            $this->stateVersion,
            $this->inputVersion,
            $this->sessionStatus,
            $this->documentId,
            $this->sourceVersion,
            $priorOutputs,
            $this->generationAttemptId,
            $this->baseInputVersion,
            $this->stage,
            $this->dependencyVersions,
            $this->claimToken,
            $this->stageAttempt,
            $this->leaseExpiresAt,
        );
    }

    public function withClaim(CheckpointClaim $claim, DateTimeImmutable $leaseExpiresAt): self
    {
        return new self(
            $this->sessionId,
            $this->organizationId,
            $this->projectId,
            $this->stateVersion,
            $this->inputVersion,
            $this->sessionStatus,
            $this->documentId,
            $this->sourceVersion,
            $this->priorOutputs,
            $this->generationAttemptId,
            $this->baseInputVersion,
            $this->stage,
            $this->dependencyVersions,
            $claim->claimToken,
            $claim->attempt,
            $leaseExpiresAt,
        );
    }
}
