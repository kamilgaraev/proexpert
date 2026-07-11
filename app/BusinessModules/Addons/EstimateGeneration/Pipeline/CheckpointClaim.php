<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use LogicException;

final readonly class CheckpointClaim
{
    private function __construct(
        public CheckpointClaimStatus $status,
        public PipelineContext $context,
        public ProcessingStage $stage,
        public ?string $claimToken,
    ) {
        if (($status === CheckpointClaimStatus::Acquired) !== ($claimToken !== null && $claimToken !== '')) {
            throw new LogicException('Only an acquired checkpoint claim may have an ownership token.');
        }
    }

    public static function acquired(PipelineContext $context, ProcessingStage $stage, string $claimToken): self
    {
        return new self(CheckpointClaimStatus::Acquired, $context, $stage, $claimToken);
    }

    public static function alreadyCompleted(PipelineContext $context, ProcessingStage $stage): self
    {
        return new self(CheckpointClaimStatus::AlreadyCompleted, $context, $stage, null);
    }

    public static function busy(PipelineContext $context, ProcessingStage $stage): self
    {
        return new self(CheckpointClaimStatus::Busy, $context, $stage, null);
    }
}
