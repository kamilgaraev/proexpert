<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

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
        );
    }
}
